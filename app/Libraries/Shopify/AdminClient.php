<?php

namespace App\Libraries\Shopify;

use App\Models\StoreModel;
use Config\Shopify as ShopifyConfig;
use RuntimeException;

/**
 * Shopify Admin GraphQL API client.
 *
 * One instance per store. The access token comes from the stores table
 * (obtained via OAuth, encrypted at rest); Config\Shopify::$adminToken is
 * only a development fallback.
 */
class AdminClient
{
    private ShopifyConfig $config;
    private string $shopDomain;
    private string $accessToken;

    public function __construct(array $store, ?ShopifyConfig $config = null)
    {
        $this->config = $config ?? config(ShopifyConfig::class);

        $domain = $store['shop_domain'] ?? $this->config->storeDomain;
        if ($domain === null || $domain === '') {
            throw new RuntimeException('Store has no shop_domain and no shopify.storeDomain fallback is set.');
        }
        $this->shopDomain = $domain;

        $token = model(StoreModel::class)->getToken($store) ?? $this->config->adminToken;
        if ($token === null || $token === '') {
            throw new RuntimeException("No access token for {$this->shopDomain}. Install the app via /shopify/install first.");
        }
        $this->accessToken = $token;
    }

    /**
     * Execute a GraphQL operation. Throws on transport or GraphQL errors.
     */
    public function graphql(string $query, array $variables = []): array
    {
        // single_service, not service: service('curlrequest') is shared, and
        // getSharedInstance() keys on the name alone — the options are only
        // honoured on the *first* call in a request. The OAuth callback builds
        // a curlrequest for the token exchange before this one, so the shared
        // instance carried no baseURI here and every install-time webhook call
        // went to https://admin/... ("Could not resolve host: admin").
        $client = single_service('curlrequest', [
            'timeout' => $this->config->timeout,
        ]);

        // Absolute URL rather than a baseURI: nothing about this request then
        // depends on how the client was constructed.
        $response = $client->post("https://{$this->shopDomain}/admin/api/{$this->config->apiVersion}/graphql.json", [
            'headers' => [
                'Content-Type'           => 'application/json',
                'X-Shopify-Access-Token' => $this->accessToken,
            ],
            'json'        => ['query' => $query, 'variables' => (object) $variables],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body   = json_decode($response->getBody(), true);

        if ($status !== 200 || ! is_array($body)) {
            throw new RuntimeException("Shopify GraphQL HTTP {$status}: " . substr((string) $response->getBody(), 0, 500));
        }
        if (! empty($body['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: ' . json_encode($body['errors']));
        }

        return $body['data'] ?? [];
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    public function getOrderForAwb(string $legacyId): ?array
    {
        $query = <<<'GQL'
        query OrderForAwb($id: ID!) {
          order(id: $id) {
            id
            legacyResourceId
            name
            createdAt
            updatedAt
            tags
            displayFinancialStatus
            displayFulfillmentStatus
            cancelledAt
            email
            totalWeight
            customer { displayName }
            totalPriceSet { shopMoney { amount currencyCode } }
            currentTotalPriceSet { shopMoney { amount } }
            currentSubtotalPriceSet { shopMoney { amount } }
            shippingLine { title code }
            customAttributes { key value }
            shippingAddress { name firstName lastName address1 address2 city province provinceCode zip phone }
            lineItems(first: 100) {
              nodes {
                quantity
                sku
                title
                variant { inventoryItem { measurement { weight { value unit } } } }
              }
            }
          }
        }
        GQL;

        return $this->graphql($query, ['id' => "gid://shopify/Order/{$legacyId}"])['order'] ?? null;
    }

    // ------------------------------------------------------------------
    // Fulfillment
    // ------------------------------------------------------------------

    public function getOpenFulfillmentOrders(string $legacyOrderId): array
    {
        $query = <<<'GQL'
        query FulfillmentOrdersForOrder($id: ID!) {
          order(id: $id) {
            fulfillmentOrders(first: 10) {
              nodes {
                id
                status
                lineItems(first: 50) { nodes { id remainingQuantity } }
              }
            }
          }
        }
        GQL;

        $nodes = $this->graphql($query, ['id' => "gid://shopify/Order/{$legacyOrderId}"])['order']['fulfillmentOrders']['nodes'] ?? [];

        return array_values(array_filter($nodes, static fn ($fo) => in_array($fo['status'], ['OPEN', 'IN_PROGRESS'], true)));
    }

    /**
     * Create a fulfillment with tracking info for every open fulfillment order.
     */
    public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
    {
        $fulfillmentOrders = $this->getOpenFulfillmentOrders($legacyOrderId);
        if ($fulfillmentOrders === []) {
            return ['skipped' => 'no open fulfillment orders'];
        }

        $mutation = <<<'GQL'
        mutation CreateFulfillment($fulfillment: FulfillmentInput!) {
          fulfillmentCreate(fulfillment: $fulfillment) {
            fulfillment { id status }
            userErrors { field message }
          }
        }
        GQL;

        $result = $this->graphql($mutation, [
            'fulfillment' => [
                'lineItemsByFulfillmentOrder' => array_map(
                    static fn ($fo) => ['fulfillmentOrderId' => $fo['id']],
                    $fulfillmentOrders
                ),
                'notifyCustomer' => $notify,
                'trackingInfo'   => [
                    'company' => $company,
                    'number'  => $number,
                    'url'     => $url,
                ],
            ],
        ]);

        $errors = $result['fulfillmentCreate']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new RuntimeException('fulfillmentCreate failed: ' . json_encode($errors));
        }

        return $result['fulfillmentCreate']['fulfillment'] ?? [];
    }

    // ------------------------------------------------------------------
    // Webhooks
    // ------------------------------------------------------------------

    /**
     * Subscribe to every topic in Config\Shopify::$webhookTopics. Safe to
     * re-run: Shopify reports an already-registered address as "taken",
     * which registerWebhook() treats as success.
     *
     * @param bool $orderTopics false for a store whose order webhooks are
     *                          switched off on this site — the order topics
     *                          are then left unregistered
     *
     * @return array<string, string> topic => 'ok' or the failure message
     */
    public function registerAppWebhooks(bool $orderTopics = true): array
    {
        $config  = config('Shopify');
        $results = [];

        foreach ($config->webhookTopics as $topic => $segment) {
            if (! $orderTopics && in_array($topic, $config->orderWebhookTopics, true)) {
                continue;
            }

            try {
                $this->registerWebhook($topic, url_to('shopify-webhook', $segment));
                $results[$topic] = 'ok';
            } catch (\Throwable $e) {
                $results[$topic] = $e->getMessage();
                log_message('warning', 'Webhook registration failed for {topic}: {msg}', [
                    'topic' => $topic,
                    'msg'   => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public function registerWebhook(string $topic, string $callbackUrl): array
    {
        $mutation = <<<'GQL'
        mutation RegisterWebhook($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic }
            userErrors { field message }
          }
        }
        GQL;

        // `uri` supersedes the deprecated `callbackUrl` input field.
        $result = $this->graphql($mutation, [
            'topic'               => $topic,
            'webhookSubscription' => ['uri' => $callbackUrl, 'format' => 'JSON'],
        ]);

        $errors = $result['webhookSubscriptionCreate']['userErrors'] ?? [];
        // "address for this topic has already been taken" is fine on re-install.
        if ($errors !== [] && stripos(json_encode($errors), 'taken') === false) {
            throw new RuntimeException('webhookSubscriptionCreate failed: ' . json_encode($errors));
        }

        return $result['webhookSubscriptionCreate']['webhookSubscription'] ?? [];
    }

    /**
     * Stop Shopify sending this site the store's order webhooks.
     *
     * Only subscriptions addressed to THIS site are deleted. The app's other
     * deployments — a development tunnel beside production, say — hold their
     * own subscriptions on the same store, and the list below returns theirs
     * too; deleting by topic alone would cut them off as well.
     *
     * @return array<string, string> topic => 'removed', 'not registered', or
     *                               the failure message
     */
    public function unregisterOrderWebhooks(): array
    {
        $config = config('Shopify');

        $query = <<<'GQL'
        query WebhookSubscriptions($first: Int!, $topics: [WebhookSubscriptionTopic!]) {
          webhookSubscriptions(first: $first, topics: $topics) {
            nodes { id topic uri }
          }
        }
        GQL;

        $subscriptions = $this->graphql($query, [
            'first'  => 100,
            'topics' => $config->orderWebhookTopics,
        ])['webhookSubscriptions']['nodes'] ?? [];

        $mutation = <<<'GQL'
        mutation DeleteWebhook($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors { field message }
          }
        }
        GQL;

        $results = [];

        foreach ($config->orderWebhookTopics as $topic) {
            $ours = rtrim(url_to('shopify-webhook', $config->webhookTopics[$topic]), '/');

            $results[$topic] = 'not registered';

            foreach ($subscriptions as $subscription) {
                if (($subscription['topic'] ?? '') !== $topic || rtrim((string) ($subscription['uri'] ?? ''), '/') !== $ours) {
                    continue;
                }

                try {
                    $errors = $this->graphql($mutation, ['id' => $subscription['id']])['webhookSubscriptionDelete']['userErrors'] ?? [];
                    if ($errors !== []) {
                        throw new RuntimeException('webhookSubscriptionDelete failed: ' . json_encode($errors));
                    }
                    $results[$topic] = 'removed';
                } catch (\Throwable $e) {
                    $results[$topic] = $e->getMessage();
                    log_message('warning', 'Webhook removal failed for {topic}: {msg}', [
                        'topic' => $topic,
                        'msg'   => $e->getMessage(),
                    ]);
                }
            }
        }

        return $results;
    }

    // ------------------------------------------------------------------
    // Carrier service
    // ------------------------------------------------------------------

    public function registerCarrierService(string $name, string $callbackUrl): array
    {
        $mutation = <<<'GQL'
        mutation RegisterCarrierService($input: DeliveryCarrierServiceCreateInput!) {
          carrierServiceCreate(input: $input) {
            carrierService { id name callbackUrl active }
            userErrors { field message }
          }
        }
        GQL;

        $result = $this->graphql($mutation, [
            'input' => [
                'name'               => $name,
                'callbackUrl'        => $callbackUrl,
                'active'             => true,
                'supportsServiceDiscovery' => true,
            ],
        ]);

        $errors = $result['carrierServiceCreate']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new RuntimeException('carrierServiceCreate failed: ' . json_encode($errors));
        }

        return $result['carrierServiceCreate']['carrierService'];
    }

    /** Carrier services configured on the shop. Requires read_shipping. */
    public function listCarrierServices(int $first = 50): array
    {
        $query = <<<'GQL'
        query CarrierServices($first: Int!) {
          carrierServices(first: $first) {
            nodes { id name callbackUrl active supportsServiceDiscovery }
          }
        }
        GQL;

        return $this->graphql($query, ['first' => $first])['carrierServices']['nodes'] ?? [];
    }

    /**
     * Point an existing carrier service at a new callback URL.
     *
     * Shopify only lets the app that created a carrier service update it, so
     * this cannot repair one registered by a different app.
     */
    public function updateCarrierService(string $id, string $name, string $callbackUrl): array
    {
        $mutation = <<<'GQL'
        mutation CarrierServiceUpdate($input: DeliveryCarrierServiceUpdateInput!) {
          carrierServiceUpdate(input: $input) {
            carrierService { id name callbackUrl active }
            userErrors { field message }
          }
        }
        GQL;

        $result = $this->graphql($mutation, [
            'input' => [
                'id'          => $id,
                'name'        => $name,
                'callbackUrl' => $callbackUrl,
                'active'      => true,
            ],
        ]);

        $errors = $result['carrierServiceUpdate']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new RuntimeException('carrierServiceUpdate failed: ' . json_encode($errors));
        }

        return $result['carrierServiceUpdate']['carrierService'];
    }

    /**
     * Register the rate callback, or re-point it if it is already registered.
     *
     * Create alone is not enough to be useful twice: Shopify rejects a second
     * service with the same name, and the callback URL is frozen at creation.
     * Since that URL is a tunnel during development, it goes stale every time
     * the tunnel restarts — and a stale one fails silently, as no rates at
     * checkout rather than an error anywhere.
     *
     * @return array{0: array<string, mixed>, 1: string} the service, and
     *               'created' | 'updated' | 'unchanged'
     */
    public function ensureCarrierService(string $name, string $callbackUrl): array
    {
        $existing = null;

        foreach ($this->listCarrierServices() as $service) {
            if (($service['name'] ?? '') === $name) {
                $existing = $service;
                break;
            }
        }

        if ($existing === null) {
            return [$this->registerCarrierService($name, $callbackUrl), 'created'];
        }

        if (($existing['callbackUrl'] ?? '') === $callbackUrl && ($existing['active'] ?? false)) {
            return [$existing, 'unchanged'];
        }

        return [$this->updateCarrierService($existing['id'], $name, $callbackUrl), 'updated'];
    }
}

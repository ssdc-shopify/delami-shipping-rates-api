<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\StoreModel;

/**
 * Shopify webhook receiver: order create/update, app/uninstalled, and the
 * three mandatory GDPR compliance topics. Every request is HMAC-verified
 * against the raw body before any processing.
 */
class ShopifyWebhooks extends BaseController
{
    public function handle(string $topic)
    {
        $rawBody    = $this->request->getBody();
        $shopDomain = (string) $this->request->getHeaderLine('X-Shopify-Shop-Domain');

        // Verify against the app secret of the store this webhook is for.
        $store  = model(StoreModel::class)->findByDomain($shopDomain);
        $secret = $store !== null ? model(StoreModel::class)->getAppSecret($store) : null;

        if ($secret === null || ! $this->verifyWebhookHmac($rawBody, $secret)) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'HMAC verification failed']);
        }

        $payload = json_decode($rawBody, true) ?? [];

        switch ($topic) {
            case 'app-uninstalled':
                $stores = model(StoreModel::class);
                $store  = $stores->findByDomain($shopDomain);
                if ($store !== null) {
                    $stores->update($store['id'], [
                        'access_token'   => null,
                        'uninstalled_at' => date('Y-m-d H:i:s'),
                    ]);
                    log_message('info', 'App uninstalled from {shop}; token revoked locally.', ['shop' => $shopDomain]);
                }
                break;

            case 'orders-create':
            case 'orders-updated':
                // Switched off for this store on this site. Normally Shopify
                // has stopped sending these, but a subscription the removal
                // missed, or a delivery already queued, can still land here.
                // Acknowledged all the same: an error would only make Shopify
                // retry, and eventually flag the endpoint as failing.
                if (! StoreModel::receivesOrderWebhooks($store)) {
                    return $this->response->setJSON(['ok' => true, 'ignored' => 'order webhooks are off for this store']);
                }

                $this->storeOrder($payload, $store);
                break;

            case 'customers-data-request':
                // What this app holds on a customer is the order rows Shopify
                // itself sent, so the merchant already has all of it. Logged
                // by id only — the payload carries the customer's contact
                // details, which a log file must not collect.
                log_message('notice', 'Privacy: data request from {shop} for customer {customer}, orders {orders}', [
                    'shop'     => $shopDomain,
                    'customer' => (string) ($payload['customer']['id'] ?? '?'),
                    'orders'   => implode(',', array_map('strval', (array) ($payload['orders_requested'] ?? []))),
                ]);
                break;

            case 'customers-redact':
                $erased = model(OrderModel::class)->redactCustomer(
                    (int) $store['id'],
                    array_map('intval', (array) ($payload['orders_to_redact'] ?? [])),
                    (string) ($payload['customer']['email'] ?? ''),
                );
                log_message('notice', 'Privacy: redacted {count} order(s) for customer {customer} of {shop}', [
                    'count'    => $erased,
                    'customer' => (string) ($payload['customer']['id'] ?? '?'),
                    'shop'     => $shopDomain,
                ]);
                break;

            case 'shop-redact':
                // Sent 48 hours after the app is uninstalled: everything this
                // site holds for the shop goes — its orders, and the keys and
                // credentials on its store row, which is deactivated.
                model(OrderModel::class)->forgetStore((int) $store['id']);
                model(StoreModel::class)->update($store['id'], [
                    'access_token'    => null,
                    'api_key'         => null,
                    'api_secret'      => null,
                    'storefront_key'  => null,
                    'server_key_hash' => null,
                    'active'          => 0,
                ]);
                log_message('notice', 'Privacy: shop redact for {shop} — orders deleted, credentials cleared', [
                    'shop' => $shopDomain,
                ]);
                break;

            default:
                return $this->response->setStatusCode(404)->setJSON(['error' => 'unknown topic']);
        }

        return $this->response->setJSON(['ok' => true]);
    }

    /**
     * Store the order, or refresh the stored copy.
     *
     * These webhooks are what the admin order list is built from: a store's
     * orders appear on this site only as Shopify sends them here, which is
     * what the per-store order webhook switch controls. An older delivery
     * than the stored copy is dropped by upsertByOrderId().
     */
    private function storeOrder(array $payload, array $store): void
    {
        if (empty($payload['id'])) {
            return;
        }

        model(OrderModel::class)->upsertByOrderId(
            OrderModel::fromWebhookPayload($payload, (int) $store['id'])
        );
    }

    /**
     * Webhook HMAC: base64 of HMAC-SHA256 over the raw body with the app
     * secret, compared against X-Shopify-Hmac-Sha256.
     */
    private function verifyWebhookHmac(string $rawBody, string $secret): bool
    {
        $given = (string) $this->request->getHeaderLine('X-Shopify-Hmac-Sha256');

        if ($secret === '' || $given === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $given);
    }
}

<?php

namespace App\Controllers;

use App\Libraries\Shopify\AdminClient;
use App\Models\StoreModel;

/**
 * Shopify OAuth 2.0 — authorization code grant for a standalone app.
 *
 * App credentials (Client ID + Client Secret) are stored per store row so
 * different brands can use different Shopify apps. The install is normally
 * kicked off from Admin → Stores → Connect.
 *
 * GET /shopify/install?store={id}   → consent screen redirect
 * GET /shopify/oauth/callback       → HMAC + state verified token exchange
 */
class ShopifyOauth extends BaseController
{
    public function install()
    {
        $stores  = model(StoreModel::class);
        $storeId = (int) $this->request->getGet('store');
        $store   = $storeId > 0 ? $stores->find($storeId) : null;

        if ($store === null) {
            return $this->response->setStatusCode(400)->setBody('Unknown store. Start from Admin → Stores → Connect.');
        }

        $shop      = $this->normalizeShop((string) $store['shop_domain']);
        $apiKey    = (string) $store['api_key'];
        $apiSecret = $stores->getAppSecret($store);

        if ($shop === null) {
            return $this->response->setStatusCode(400)->setBody('Store has an invalid shop domain.');
        }
        if ($apiKey === '' || $apiSecret === null) {
            return $this->response->setStatusCode(400)->setBody('Store is missing Client ID / Secret. Re-enter them in Admin → Stores.');
        }

        $state = bin2hex(random_bytes(16));
        session()->setTempdata('shopify_oauth_state', $state, 600);
        session()->setTempdata('shopify_oauth_store', $store['id'], 600);

        $authorizeUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $apiKey,
            'scope'        => config('Shopify')->scopes,
            'redirect_uri' => url_to('shopify-oauth-callback'),
            'state'        => $state,
        ]);

        return redirect()->to($authorizeUrl);
    }

    public function callback()
    {
        $params = $this->request->getGet();
        $shop   = $this->normalizeShop((string) ($params['shop'] ?? ''));
        $code   = (string) ($params['code'] ?? '');
        $state  = (string) ($params['state'] ?? '');

        $stores  = model(StoreModel::class);
        $storeId = (int) session()->getTempdata('shopify_oauth_store');
        $store   = $storeId > 0 ? $stores->find($storeId) : null;

        if ($shop === null || $code === '' || $store === null) {
            return $this->response->setStatusCode(400)->setBody('Missing shop, code, or store context — restart from Admin → Stores.');
        }
        if (! hash_equals((string) session()->getTempdata('shopify_oauth_state'), $state)
            || $this->normalizeShop((string) $store['shop_domain']) !== $shop) {
            return $this->response->setStatusCode(401)->setBody('State/shop mismatch — restart the install.');
        }

        $apiKey    = (string) $store['api_key'];
        $apiSecret = $stores->getAppSecret($store);
        if ($apiSecret === null) {
            return $this->response->setStatusCode(400)->setBody('Store app secret is missing.');
        }
        if (! $this->verifyHmac($params, $apiSecret)) {
            return $this->response->setStatusCode(401)->setBody('HMAC verification failed');
        }

        // Exchange the authorization code for an offline access token.
        $client   = single_service('curlrequest', ['timeout' => config('Shopify')->timeout]);
        $response = $client->post("https://{$shop}/admin/oauth/access_token", [
            'form_params' => [
                'client_id'     => $apiKey,
                'client_secret' => $apiSecret,
                'code'          => $code,
            ],
            'headers'     => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        $body = json_decode($response->getBody(), true);
        if ($response->getStatusCode() !== 200 || empty($body['access_token'])) {
            log_message('error', 'Token exchange failed for {shop}: {body}', [
                'shop' => $shop,
                'body' => substr((string) $response->getBody(), 0, 500),
            ]);

            return $this->response->setStatusCode(502)->setBody('Token exchange with Shopify failed');
        }

        $stores->saveToken((int) $store['id'], $body['access_token'], (string) ($body['scope'] ?? ''));

        // Subscribe to the uninstall webhook, and to the order webhooks unless
        // they are switched off for this store on this site.
        try {
            $installed = $stores->find($store['id']);
            (new AdminClient($installed))->registerAppWebhooks(StoreModel::receivesOrderWebhooks($installed));
        } catch (\Throwable $e) {
            log_message('warning', 'Webhook registration failed for {shop}: {msg}', ['shop' => $shop, 'msg' => $e->getMessage()]);
        }

        session()->removeTempdata('shopify_oauth_state');
        session()->removeTempdata('shopify_oauth_store');

        return redirect()->to('/admin/stores')->with('message', "Shopify app installed for {$shop}");
    }

    /**
     * HMAC-SHA256 verification of Shopify OAuth query params
     * (sorted, hmac removed, compared in constant time).
     */
    private function verifyHmac(array $params, string $secret): bool
    {
        $hmac = (string) ($params['hmac'] ?? '');
        unset($params['hmac']);
        ksort($params);

        $computed = hash_hmac('sha256', http_build_query($params), $secret);

        return $hmac !== '' && hash_equals($computed, $hmac);
    }

    private function normalizeShop(string $shop): ?string
    {
        $shop = strtolower(trim($shop));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) === 1 ? $shop : null;
    }
}

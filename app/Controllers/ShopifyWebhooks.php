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
            case 'customers-redact':
            case 'shop-redact':
                // Receipt is logged for the audit trail. Orders received by
                // webhook ARE stored here (storeOrder), with the customer's
                // name, email and address, so a redact request still needs
                // acting on — not implemented yet.
                log_message('info', 'GDPR webhook {topic} received from {shop}: {payload}', [
                    'topic'   => $topic,
                    'shop'    => $shopDomain,
                    'payload' => json_encode($payload),
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

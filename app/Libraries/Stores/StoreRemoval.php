<?php

namespace App\Libraries\Stores;

use App\Libraries\Shopify\AdminClient;
use RuntimeException;

/**
 * Deleting a store from this site — the Shopify side first, then the data.
 *
 * A store row is not all there is to it. While the app stays installed,
 * Shopify keeps sending this site the store's webhooks and calling its
 * checkout rate callback, so deleting only the row left checkout quietly
 * without this app's rates and every webhook failing here. So:
 *
 *  1. On Shopify, remove what points at THIS site — its webhook
 *     subscriptions, and the carrier service if its callback is on this
 *     site. Another deployment's registrations for the same store are its
 *     own, and stay. Best effort: an unreachable store does not block the
 *     delete, and the report says what was left behind.
 *  2. Here, delete the store's orders, their airway bills and the store
 *     itself, in one transaction — all of it or none of it.
 *
 * The app itself stays installed on the Shopify store; uninstalling it is
 * the merchant's action, in the store's own admin.
 */
final class StoreRemoval
{
    /**
     * @param \Closure(array<string, mixed>): AdminClient|null $shopifyFor
     *        builds the Shopify client for a store; a test passes a fake
     */
    public function __construct(private ?\Closure $shopifyFor = null)
    {
    }

    /**
     * @param array<string, mixed> $store a stores row
     *
     * @return array{orders: int, shipments: int, shopify: array<string, mixed>}
     */
    public function remove(array $store): array
    {
        // Shopify first: the access token needed to reach it lives on the row
        // about to be deleted.
        $shopify = $this->detachFromShopify($store);

        [$orders, $shipments] = $this->deleteLocally((int) $store['id']);

        return ['orders' => $orders, 'shipments' => $shipments, 'shopify' => $shopify];
    }

    /**
     * @return array<string, mixed> ['skipped' => why] | ['error' => message]
     *         | ['webhooks' => topic => result, 'carrier' => result]
     */
    private function detachFromShopify(array $store): array
    {
        if (empty($store['access_token']) || ! empty($store['uninstalled_at'])) {
            return ['skipped' => 'not installed'];
        }

        $config   = config('Shopify');
        $callback = rtrim(config('App')->baseURL, '/') . '/carrier/rates/' . $store['slug']
            . '?token=' . $config->carrierCallbackToken;

        try {
            $client = $this->shopifyFor !== null ? ($this->shopifyFor)($store) : new AdminClient($store);

            return [
                'webhooks' => $client->unregisterWebhooks(array_keys($config->webhookTopics)),
                'carrier'  => $client->removeCarrierService($config->carrierServiceName, $callback),
            ];
        } catch (\Throwable $e) {
            log_message('warning', 'Store {slug} deleted, but Shopify could not be cleaned up: {msg}', [
                'slug' => $store['slug'],
                'msg'  => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array{0: int, 1: int} [orders deleted, airway bills deleted]
     */
    private function deleteLocally(int $storeId): array
    {
        $db = db_connect();
        $db->transStart();

        // Airway bills carry no store of their own; they belong to the store
        // through the order they were booked for.
        $orderIds = array_column(
            $db->table('orders')->select('order_id')->where('store_id', $storeId)->get()->getResultArray(),
            'order_id',
        );

        $shipments = 0;
        foreach (array_chunk($orderIds, 500) as $chunk) {
            $db->table('airwaybills')->whereIn('order_id', $chunk)->delete();
            $shipments += $db->affectedRows();
        }

        $db->table('orders')->where('store_id', $storeId)->delete();
        $orders = $db->affectedRows();

        $db->table('stores')->where('id', $storeId)->delete();

        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new RuntimeException('Deleting the store failed, and nothing was removed.');
        }

        return [$orders, $shipments];
    }
}

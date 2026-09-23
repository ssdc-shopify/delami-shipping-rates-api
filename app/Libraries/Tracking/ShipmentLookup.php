<?php

namespace App\Libraries\Tracking;

use App\Libraries\Awb\AwbService;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;

/**
 * Resolving "my order number and my email" to one shipment.
 *
 * Shared by the hosted tracking page and the storefront JSON endpoint, so the
 * two cannot drift apart on the rule that matters: a reference is only ever
 * answered when it is presented together with the email address on that order,
 * and a wrong pair is indistinguishable from a reference that does not exist.
 *
 * Read-only throughout.
 */
class ShipmentLookup
{
    /**
     * @param array<string, mixed>|null $store when given, only shipments
     *                                         belonging to that store resolve
     *
     * @return array{awb: array<string, mixed>, summary: array<string, string>}|null
     */
    public function find(string $reference, string $email, ?array $store = null): ?array
    {
        $reference = trim($reference);
        $email     = trim($email);

        if ($reference === '' || $email === '') {
            return null;
        }

        $awbs   = model(AirwaybillModel::class);
        $orders = model(OrderModel::class);

        // A waybill first: it is what the shipping confirmation leads with, so
        // it is what most shoppers will paste.
        $awb   = $awbs->where('waybill', $reference)->first();
        $order = null;

        if ($awb === null) {
            $order = $this->findOrderByReference($orders, $reference);

            if ($order !== null) {
                $awb = $awbs->findByOrderId($order['order_id']);
            }
        }

        if ($awb === null) {
            return null;
        }

        $order ??= $orders->findByOrderId($awb['order_id']);

        // A key for one store must not read another store's orders. Rows that
        // predate store_id carry none, and are left to the Shopify check
        // below — which is scoped to the caller's store anyway.
        if ($store !== null && $order !== null && ! empty($order['store_id'])
            && (int) $order['store_id'] !== (int) $store['id']) {
            return null;
        }

        $live = null;

        // The local orders row is written by the order webhook. When it is
        // missing — webhooks not yet registered, or an order predating them —
        // Shopify is the fallback rather than a dead end.
        if ($order === null) {
            $live = $this->liveOrder($awb, null, $store);

            if ($live === null) {
                return null;
            }
        }

        if (! $this->emailMatches($email, $order, $awb, $live, $store)) {
            return null;
        }

        return [
            'awb'     => $awb,
            'summary' => $this->summary($awb, $order, $live),
        ];
    }

    /**
     * Find a local order from what the shopper typed: "#1001", "1001", or the
     * order name exactly as Shopify prints it.
     *
     * @return array<string, mixed>|null
     */
    private function findOrderByReference(OrderModel $orders, string $reference): ?array
    {
        $bare = ltrim($reference, '#');

        if ($bare !== '' && ctype_digit($bare)) {
            $order = $orders->where('order_number', (int) $bare)->first();
            if ($order !== null) {
                return $order;
            }
        }

        return $orders->where('order_name', '#' . $bare)->first()
            ?? $orders->where('order_name', $reference)->first();
    }

    /**
     * Does the address given match the one on the order?
     *
     * hash_equals rather than ===, so the comparison does not leak how much of
     * an address was right through its timing.
     */
    private function emailMatches(string $given, ?array $order, array $awb, ?array $live, ?array $store): bool
    {
        $stored = strtolower(trim((string) ($order['email'] ?? '')));

        // A local row with no email on it proves nothing either way, so fall
        // through to Shopify rather than rejecting a legitimate shopper.
        if ($stored === '') {
            $live ??= $this->liveOrder($awb, $order, $store);
            $stored = strtolower(trim((string) ($live['email'] ?? '')));
        }

        return $stored !== '' && hash_equals($stored, strtolower($given));
    }

    /**
     * The order as Shopify has it now. Null on any failure — a store that is
     * unreachable must read as "not found", never as a match.
     *
     * @return array<string, mixed>|null
     */
    private function liveOrder(array $awb, ?array $order, ?array $store): ?array
    {
        try {
            if ($store === null && $order !== null && ! empty($order['store_id'])) {
                $store = model(StoreModel::class)->find($order['store_id']);
            }

            return (new AwbService($store))->shopify()->getOrderForAwb((string) $awb['order_id']);
        } catch (\Throwable $e) {
            log_message('error', 'Tracking lookup could not reach Shopify for order {id}: {msg}', [
                'id'  => $awb['order_id'],
                'msg' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * What is shown above the timeline. Only what the shopper already knows —
     * their own order — and never the full street address.
     *
     * @return array<string, string>
     */
    private function summary(array $awb, ?array $order, ?array $live): array
    {
        $address = $live['shippingAddress'] ?? [];

        $city = (string) ($order['ship_city'] ?? $address['city'] ?? '');
        $prov = (string) ($order['ship_province'] ?? $address['province'] ?? '');

        return [
            'orderName'   => (string) ($order['order_name'] ?? $live['name'] ?? '#' . $awb['order_id']),
            'recipient'   => (string) ($order['ship_name'] ?? $address['name'] ?? ''),
            'destination' => trim($city . ($city !== '' && $prov !== '' ? ', ' : '') . $prov),
            'orderedAt'   => (string) ($order['ordered_at'] ?? $awb['created_at'] ?? ''),
            'bookedAt'    => (string) ($awb['created_at'] ?? ''),
        ];
    }
}

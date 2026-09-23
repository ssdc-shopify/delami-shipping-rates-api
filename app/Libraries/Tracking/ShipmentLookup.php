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
     * Where the ORDER is, for a shopper — separate from where the parcel is,
     * which only exists once it has a waybill.
     *
     * Orders reach this app by webhook the moment they are placed, so an order
     * that has not shipped yet is found too, and told apart from one that does
     * not exist: "being prepared" instead of "we could not find it". Still only
     * ever answered to the email on the order.
     */
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PROCESSING       = 'processing';
    public const STATUS_SHIPPED          = 'shipped';
    public const STATUS_CANCELLED        = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
        self::STATUS_PROCESSING       => 'Being prepared',
        self::STATUS_SHIPPED          => 'Shipped',
        self::STATUS_CANCELLED        => 'Cancelled',
    ];

    /**
     * Most rows a reference is matched against. Two stores sharing an order
     * number is expected; more than a handful of matches is not a shopper.
     */
    private const MAX_CANDIDATES = 10;

    /**
     * @param array<string, mixed>|null $store when given, only shipments
     *                                         belonging to that store resolve
     *
     * @return array{awb: array<string, mixed>|null, summary: array<string, string>}|null
     *         awb is null — or has no waybill — while the order is not shipped
     */
    public function find(string $reference, string $email, ?array $store = null): ?array
    {
        $reference = trim($reference);
        $email     = trim($email);

        if ($reference === '' || $email === '') {
            return null;
        }

        // Every shipment the reference could mean, and the first whose order
        // carries this email. Order numbers are per store, so two stores can
        // both have a #1001 — taking whichever row came first answered "not
        // found" to the shopper whose order happened to sort second.
        foreach ($this->candidates($reference, $store) as [$awb, $order]) {
            $match = $this->resolve($awb, $order, $email, $store);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * The (airwaybill, order) pairs a reference could mean: a waybill first —
     * it is what the shipping confirmation leads with — then an order number
     * or name, within $store when the caller has one. An order that has no
     * airway bill yet is a candidate too, with a null one.
     *
     * @return list<array{0: array<string, mixed>|null, 1: array<string, mixed>|null}>
     */
    private function candidates(string $reference, ?array $store): array
    {
        $awbs   = model(AirwaybillModel::class);
        $orders = model(OrderModel::class);
        $pairs  = [];
        $seen   = [];

        foreach ($awbs->where('waybill', $reference)->findAll(self::MAX_CANDIDATES) as $awb) {
            $seen[$awb['id']] = true;
            $pairs[]          = [$awb, $orders->findByOrderId($awb['order_id'])];
        }

        foreach ($this->ordersByReference($reference, $store) as $order) {
            $awb = $awbs->findByOrderId($order['order_id']);
            if ($awb !== null && isset($seen[$awb['id']])) {
                continue;
            }
            if ($awb !== null) {
                $seen[$awb['id']] = true;
            }
            $pairs[] = [$awb, $order];
        }

        return $pairs;
    }

    /**
     * Orders a shopper's reference could name: "#1001", "1001", or the order
     * name exactly as Shopify prints it.
     *
     * @return list<array<string, mixed>>
     */
    private function ordersByReference(string $reference, ?array $store): array
    {
        $bare  = ltrim($reference, '#');
        $query = model(OrderModel::class)->groupStart()
            ->whereIn('order_name', array_values(array_unique(['#' . $bare, $reference])));

        if ($bare !== '' && ctype_digit($bare)) {
            $query->orWhere('order_number', (int) $bare);
        }

        $query->groupEnd();

        if ($store !== null) {
            $query->where('store_id', $store['id']);
        }

        return $query->findAll(self::MAX_CANDIDATES);
    }

    /**
     * One candidate, checked: the shipment and its summary when the caller may
     * see it with this email, else null.
     */
    private function resolve(?array $awb, ?array $order, string $email, ?array $store): ?array
    {
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
            $live = $this->liveOrder($awb['order_id'] ?? '', null, $store);

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
     * Does the address given match the one on the order?
     *
     * hash_equals rather than ===, so the comparison does not leak how much of
     * an address was right through its timing.
     */
    private function emailMatches(string $given, ?array $order, ?array $awb, ?array $live, ?array $store): bool
    {
        $stored = strtolower(trim((string) ($order['email'] ?? '')));

        // A local row with no email on it proves nothing either way, so fall
        // through to Shopify rather than rejecting a legitimate shopper.
        if ($stored === '') {
            $live ??= $this->liveOrder($awb['order_id'] ?? $order['order_id'] ?? '', $order, $store);
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
    private function liveOrder(int|string $orderId, ?array $order, ?array $store): ?array
    {
        if ((string) $orderId === '') {
            return null;
        }

        try {
            if ($store === null && $order !== null && ! empty($order['store_id'])) {
                $store = model(StoreModel::class)->find($order['store_id']);
            }

            return (new AwbService($store))->shopify()->getOrderForAwb((string) $orderId);
        } catch (\Throwable $e) {
            log_message('error', 'Tracking lookup could not reach Shopify for order {id}: {msg}', [
                'id'  => $orderId,
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
    private function summary(?array $awb, ?array $order, ?array $live): array
    {
        $address = $live['shippingAddress'] ?? [];

        $city = (string) ($order['ship_city'] ?? $address['city'] ?? '');
        $prov = (string) ($order['ship_province'] ?? $address['province'] ?? '');

        $status  = self::status($awb, $order, $live);
        $shipped = $status === self::STATUS_SHIPPED;

        return [
            'orderName'   => (string) ($order['order_name'] ?? $live['name'] ?? '#' . ($awb['order_id'] ?? '')),
            'status'      => $status,
            'statusLabel' => self::STATUS_LABELS[$status],
            'recipient'   => (string) ($order['ship_name'] ?? $address['name'] ?? ''),
            'destination' => trim($city . ($city !== '' && $prov !== '' ? ', ' : '') . $prov),
            // The rate the shopper chose, e.g. "JNE - REGULER".
            'service'     => self::serviceName((string) ($order['shipping_title'] ?? $live['shippingLine']['title'] ?? '')),
            'serviceCode' => (string) ($order['shipping_code'] ?? $live['shippingLine']['code'] ?? ''),
            'orderedAt'   => (string) ($order['ordered_at'] ?? self::localTime($live['createdAt'] ?? null) ?? $awb['created_at'] ?? ''),
            // When the parcel was handed to the courier; empty until it is.
            'bookedAt'    => $shipped ? (string) ($awb['created_at'] ?? '') : '',
        ];
    }

    /**
     * The order's state for a shopper. Cancelled wins; a waybill means it has
     * shipped; otherwise payment decides between waiting and being prepared.
     */
    private static function status(?array $awb, ?array $order, ?array $live): string
    {
        $cancelled = $order['cancelled_at'] ?? $live['cancelledAt'] ?? null;
        $payment   = strtolower((string) ($order['financial_status'] ?? $live['displayFinancialStatus'] ?? ''));

        if (! empty($cancelled) || in_array($payment, ['refunded', 'voided'], true)) {
            return self::STATUS_CANCELLED;
        }

        if (! empty($awb['waybill'])) {
            return self::STATUS_SHIPPED;
        }

        return in_array($payment, ['paid', 'partially_refunded'], true)
            ? self::STATUS_PROCESSING
            : self::STATUS_AWAITING_PAYMENT;
    }

    /**
     * A rate title as a service name: "SPX - HEMAT. (Subsidi Rp 5.000)" is
     * "SPX - HEMAT". The suffix and the trailing full stop are pricing notes
     * from checkout, not part of what the parcel is travelling by.
     */
    private static function serviceName(string $title): string
    {
        return rtrim(trim((string) preg_replace('/\s*\(.*\)\s*$/', '', $title)), '. ');
    }

    /** Shopify's ISO time in the app's timezone, matching the stored columns. */
    private static function localTime(?string $iso): ?string
    {
        $ts = ($iso === null || $iso === '') ? false : strtotime($iso);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}

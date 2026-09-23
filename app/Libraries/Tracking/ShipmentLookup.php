<?php

namespace App\Libraries\Tracking;

use App\Libraries\Shopify\AdminClient;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;

/**
 * Resolving "my order number and my email" to one order, and its parcel.
 *
 * Shared by the hosted tracking page, the storefront JSON endpoint and the
 * admin Track Simulator, so they cannot drift apart on the rule that matters:
 * a reference is only ever answered when it is presented together with the
 * email address on that order, and a wrong pair is indistinguishable from a
 * reference that does not exist.
 *
 * WHERE THE ORDER COMES FROM. Not every store sends this site its order
 * webhooks — it is a per-store, per-site switch — so the local orders table
 * cannot be the only source:
 *
 *  1. The local database first. Fast, and it still has orders older than the
 *     60 days Shopify's Order API returns by default.
 *  2. Shopify's own copy of that order, whenever the local row cannot be
 *     trusted as it stands: there is none, the store's order webhooks are off
 *     here (so it may be stale), or it is fulfilled while this site holds no
 *     waybill — shipped by something else, whose tracking only Shopify has.
 *  3. Nothing here at all: Shopify is searched — by order name, then by
 *     tracking number (its free-text search covers fulfillment tracking
 *     numbers) — in the caller's store (a storefront key) or in every
 *     connected store (the hosted page).
 *
 * WHERE THE PARCEL COMES FROM. This site's airway bill when it booked one;
 * otherwise the tracking number on the order's Shopify fulfillment, traced
 * with that courier — so a store shipped by another system is still tracked.
 *
 * Read-only throughout: it reads, and asks Shopify and couriers; it writes
 * nothing.
 */
class ShipmentLookup
{
    /**
     * Where the ORDER is, for a shopper — separate from where the parcel is,
     * which only exists once it has a tracking number.
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
     * How long "this store has no order by that name" is remembered, in
     * seconds — so a shopper retrying, or someone guessing, does not spend the
     * store's Shopify API budget on the same empty search again and again.
     */
    private const NO_ORDER_TTL = 60;

    /**
     * @param \Closure(array<string, mixed>): AdminClient|null $shopifyFor
     *        builds a store's Shopify client; a test passes a fake
     */
    public function __construct(private ?\Closure $shopifyFor = null)
    {
    }

    /**
     * @param array<string, mixed>|null $store when given, only that store's
     *                                         orders resolve — a storefront key
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

        // Stores whose local copy of this order was found and is up to date:
        // Shopify has nothing to add for them, so they are not searched again.
        $answeredHere = [];

        foreach ($this->candidates($reference, $store) as [$awb, $order]) {
            $match = $this->resolve($awb, $order, $email, $store);
            if ($match !== null) {
                return $match;
            }

            $owner = $order === null ? null : $this->storeOf($order);
            if ($owner !== null && StoreModel::receivesOrderWebhooks($owner)) {
                $answeredHere[(int) $owner['id']] = true;
            }
        }

        // Nothing here answers: the store may not send this site its order
        // webhooks, or the order predates them. Ask Shopify itself.
        return $this->searchShopify($reference, $email, $store, $answeredHere);
    }

    // ------------------------------------------------------------------
    // The local database
    // ------------------------------------------------------------------

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
     * One local candidate, checked — and refreshed from Shopify when the local
     * row cannot be trusted as it stands.
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

        $owner   = $store ?? ($order === null ? null : $this->storeOf($order));
        $orderId = (string) ($awb['order_id'] ?? $order['order_id'] ?? '');
        $view    = $order === null ? null : self::fromLocal($order);
        $live    = null;

        $stale = $order === null
            || ($owner !== null && ! StoreModel::receivesOrderWebhooks($owner))
            || (empty($awb['waybill']) && self::fulfilledElsewhere($order))
            || $view['email'] === ''; // a row with no email proves nothing either way

        if ($stale) {
            $live = $this->liveOrder($orderId, $owner);
            if ($live !== null) {
                $view = self::fromShopify($live);
            }
        }

        // No usable copy anywhere — an unreachable store reads as "not found",
        // never as a match.
        if ($view === null || ! self::sameEmail($email, $view['email'])) {
            return null;
        }

        if (empty($awb['waybill']) && $live !== null) {
            $awb = self::shipmentFromFulfillment($live) ?? $awb;
        }

        return ['awb' => $awb, 'summary' => self::summary($awb, $view)];
    }

    // ------------------------------------------------------------------
    // Shopify
    // ------------------------------------------------------------------

    /**
     * Search Shopify for the order, in the caller's store or in every
     * connected one, skipping stores whose up-to-date local copy was already
     * checked: by order name first, then — the reference may be the tracking
     * number from a shipping confirmation — by tracking number.
     *
     * @param array<int, true> $answeredHere store ids to skip
     */
    private function searchShopify(string $reference, string $email, ?array $store, array $answeredHere): ?array
    {
        $bare = ltrim($reference, '#');
        if ($bare === '') {
            return null;
        }
        $name = '#' . $bare;

        foreach ($store !== null ? [$store] : $this->connectedStores() as $candidate) {
            if (isset($answeredHere[(int) $candidate['id']]) || ! self::connected($candidate)) {
                continue;
            }

            $noOrder = 'track_no_order_' . md5($candidate['id'] . '|' . strtolower($reference));
            if (service('cache')->get($noOrder) !== null) {
                continue;
            }

            try {
                $client  = $this->shopify($candidate);
                $matches = array_filter($client->ordersForTracking($name),
                    static fn (array $node) => strcasecmp((string) ($node['name'] ?? ''), $name) === 0);
                $byNumber = null;

                if ($matches === []) {
                    $matches = array_filter($client->ordersByTrackingNumber($reference),
                        static fn (array $node) => self::trackingNumbersOf($node, $reference) !== []);
                    $byNumber = $reference;
                }
            } catch (\Throwable $e) {
                log_message('error', 'Tracking could not search Shopify for {ref} in {slug}: {msg}', [
                    'ref'  => $reference,
                    'slug' => $candidate['slug'] ?? '?',
                    'msg'  => $e->getMessage(),
                ]);

                continue;
            }

            if ($matches === []) {
                service('cache')->save($noOrder, 1, self::NO_ORDER_TTL);

                continue;
            }

            foreach ($matches as $node) {
                $view = self::fromShopify($node);
                if (! self::sameEmail($email, $view['email'])) {
                    continue;
                }

                $awb = model(AirwaybillModel::class)->findByOrderId($view['orderId']);
                if (empty($awb['waybill'])) {
                    $awb = self::shipmentFromFulfillment($node, $byNumber) ?? $awb;
                }

                return ['awb' => $awb, 'summary' => self::summary($awb, $view)];
            }
        }

        return null;
    }

    /**
     * The tracking entries on an order's fulfillments whose number is
     * $number — compared exactly, ignoring case.
     *
     * @return list<array<string, mixed>>
     */
    private static function trackingNumbersOf(array $order, string $number): array
    {
        $found = [];

        foreach ($order['fulfillments'] ?? [] as $fulfillment) {
            foreach ($fulfillment['trackingInfo'] ?? [] as $info) {
                if (strcasecmp(trim((string) ($info['number'] ?? '')), $number) === 0) {
                    $found[] = $info;
                }
            }
        }

        return $found;
    }

    /**
     * The order as Shopify has it now, with its fulfillments' tracking. Null
     * on any failure. An order id is global to Shopify, so with no store to
     * ask, each connected store is tried.
     */
    private function liveOrder(string $orderId, ?array $store): ?array
    {
        if ($orderId === '') {
            return null;
        }

        foreach ($store !== null ? [$store] : $this->connectedStores() as $candidate) {
            if (! self::connected($candidate)) {
                continue;
            }

            try {
                $order = $this->shopify($candidate)->orderForTracking($orderId);
                if ($order !== null) {
                    return $order;
                }
            } catch (\Throwable $e) {
                log_message('error', 'Tracking lookup could not reach Shopify for order {id}: {msg}', [
                    'id'  => $orderId,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * The tracking number on the order's latest Shopify fulfillment, as an
     * airway-bill-shaped row TrackingService can trace — or null when nothing
     * has been fulfilled with one.
     *
     * The carrier name Shopify holds ("JNE", "Ninja Xpress", "Shopee Xpress")
     * maps to the courier this app traces; one it does not know is kept by
     * name and linked out, never guessed as one it does.
     *
     * @param string|null $number the tracking number the shopper gave, when
     *                            they looked up by it — that one is shown
     */
    private static function shipmentFromFulfillment(array $order, ?string $number = null): ?array
    {
        foreach (array_reverse($order['fulfillments'] ?? []) as $fulfillment) {
            foreach ($fulfillment['trackingInfo'] ?? [] as $info) {
                $found = trim((string) ($info['number'] ?? ''));
                if ($found === '' || ($number !== null && strcasecmp($found, $number) !== 0)) {
                    continue;
                }

                $company = trim((string) ($info['company'] ?? ''));

                return [
                    'order_id'     => (string) ($order['legacyResourceId'] ?? ''),
                    'courier'      => TrackingService::courierFromCompany($company) ?? TrackingService::COURIER_OTHER,
                    'courier_name' => $company,
                    'waybill'      => $found,
                    'tracking_url' => (string) ($info['url'] ?? ''),
                    'created_at'   => self::localTime($fulfillment['createdAt'] ?? null) ?? '',
                ];
            }
        }

        return null;
    }

    private function shopify(array $store): AdminClient
    {
        return $this->shopifyFor !== null ? ($this->shopifyFor)($store) : new AdminClient($store);
    }

    /** @return list<array<string, mixed>> stores this app can ask Shopify about */
    private function connectedStores(): array
    {
        return model(StoreModel::class)->where('active', 1)
            ->where('access_token IS NOT NULL')->where('uninstalled_at', null)
            ->orderBy('id')->findAll();
    }

    private static function connected(array $store): bool
    {
        return ! empty($store['access_token']) && empty($store['uninstalled_at']);
    }

    private function storeOf(array $order): ?array
    {
        return empty($order['store_id']) ? null : model(StoreModel::class)->find($order['store_id']);
    }

    // ------------------------------------------------------------------
    // One view of an order, from either source
    // ------------------------------------------------------------------

    /** A local orders row as the fields tracking reads. */
    private static function fromLocal(array $order): array
    {
        return [
            'orderId'      => (string) $order['order_id'],
            'name'         => (string) ($order['order_name'] ?? ''),
            'email'        => strtolower(trim((string) ($order['email'] ?? ''))),
            'cancelled'    => ! empty($order['cancelled_at']),
            'payment'      => strtolower((string) ($order['financial_status'] ?? '')),
            'fulfillment'  => strtolower((string) ($order['fulfillment_status'] ?? '')),
            'recipient'    => (string) ($order['ship_name'] ?? ''),
            'city'         => (string) ($order['ship_city'] ?? ''),
            'province'     => (string) ($order['ship_province'] ?? ''),
            'serviceTitle' => (string) ($order['shipping_title'] ?? ''),
            'serviceCode'  => (string) ($order['shipping_code'] ?? ''),
            'orderedAt'    => (string) ($order['ordered_at'] ?? ''),
        ];
    }

    /** A Shopify order node as the same fields. */
    private static function fromShopify(array $order): array
    {
        return [
            'orderId'      => (string) ($order['legacyResourceId'] ?? ''),
            'name'         => (string) ($order['name'] ?? ''),
            'email'        => strtolower(trim((string) ($order['email'] ?? ''))),
            'cancelled'    => ! empty($order['cancelledAt']),
            'payment'      => strtolower((string) ($order['displayFinancialStatus'] ?? '')),
            'fulfillment'  => strtolower((string) ($order['displayFulfillmentStatus'] ?? '')),
            'recipient'    => (string) ($order['shippingAddress']['name'] ?? ''),
            'city'         => (string) ($order['shippingAddress']['city'] ?? ''),
            'province'     => (string) ($order['shippingAddress']['province'] ?? ''),
            'serviceTitle' => (string) ($order['shippingLine']['title'] ?? ''),
            'serviceCode'  => (string) ($order['shippingLine']['code'] ?? ''),
            'orderedAt'    => self::localTime($order['createdAt'] ?? null) ?? '',
        ];
    }

    /** Marked fulfilled locally — so shipped, whether or not this site booked it. */
    private static function fulfilledElsewhere(?array $order): bool
    {
        return in_array(strtolower((string) ($order['fulfillment_status'] ?? '')), ['fulfilled', 'partially_fulfilled', 'partial'], true);
    }

    /**
     * Does the address given match the one on the order? hash_equals rather
     * than ===, so the comparison does not leak how much of it was right.
     */
    private static function sameEmail(string $given, string $stored): bool
    {
        return $stored !== '' && hash_equals($stored, strtolower(trim($given)));
    }

    /**
     * What is shown above the timeline. Only what the shopper already knows —
     * their own order — and never the full street address.
     *
     * @return array<string, string>
     */
    private static function summary(?array $awb, array $view): array
    {
        $status  = self::status($awb, $view);
        $shipped = $status === self::STATUS_SHIPPED;
        $city    = $view['city'];
        $prov    = $view['province'];

        $tracked = ! empty($awb['waybill']);

        return [
            'orderName'   => $view['name'] !== '' ? $view['name'] : '#' . $view['orderId'],
            'status'      => $status,
            'statusLabel' => self::STATUS_LABELS[$status],
            // Said plainly whenever there is nothing to trace yet — including
            // an order fulfilled without a tracking number.
            'note'        => ! $tracked && $status !== self::STATUS_CANCELLED
                ? 'This order does not have a tracking number yet.'
                : '',
            'recipient'   => $view['recipient'],
            'destination' => trim($city . ($city !== '' && $prov !== '' ? ', ' : '') . $prov),
            // The rate the shopper chose, e.g. "JNE - REGULER".
            'service'     => self::serviceName($view['serviceTitle']),
            'serviceCode' => $view['serviceCode'],
            'orderedAt'   => $view['orderedAt'] !== '' ? $view['orderedAt'] : (string) ($awb['created_at'] ?? ''),
            // When the parcel was handed to the courier; empty until it is.
            'bookedAt'    => $shipped && $tracked ? (string) ($awb['created_at'] ?? '') : '',
        ];
    }

    /**
     * The order's state for a shopper. Cancelled wins; a tracking number — or
     * Shopify marking it fulfilled, with or without one — means it has
     * shipped; otherwise payment decides between waiting and being prepared.
     */
    private static function status(?array $awb, array $view): string
    {
        if ($view['cancelled'] || in_array($view['payment'], ['refunded', 'voided'], true)) {
            return self::STATUS_CANCELLED;
        }

        if (! empty($awb['waybill']) || in_array($view['fulfillment'], ['fulfilled', 'partially_fulfilled', 'partial'], true)) {
            return self::STATUS_SHIPPED;
        }

        return in_array($view['payment'], ['paid', 'partially_refunded'], true)
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

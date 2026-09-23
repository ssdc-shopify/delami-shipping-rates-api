<?php

namespace App\Libraries\Awb;

use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\JneClient;
use App\Libraries\Couriers\NinjaClient;
use App\Libraries\Couriers\SpxClient;
use App\Libraries\Couriers\WidgetProxyClient;
use App\Libraries\Shipping\RateEngine;
use App\Libraries\Shopify\AdminClient;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use Config\Couriers as CouriersConfig;
use RuntimeException;
use Throwable;

/**
 * AWB pipeline: order intake → routing → AWB generation (idempotent for
 * every courier) → Shopify fulfillment → delivery tracking.
 */
class AwbService
{
    public const NUMBER_PREFIX = '95';

    private array $store;
    private AirwaybillModel $awbs;
    private CouriersConfig $couriers;
    private ?AdminClient $shopify = null;

    public function __construct(?array $store = null)
    {
        $this->awbs     = model(AirwaybillModel::class);
        $this->couriers = config(CouriersConfig::class);

        if ($store === null) {
            $stores = model(StoreModel::class);
            // Prefer the legacy 'exe' slug, else the first connected store.
            $store = $stores->findBySlug('exe')
                ?? $stores->where('access_token IS NOT NULL')->where('active', 1)->first();
            if ($store === null) {
                throw new RuntimeException('No connected store found — install the app via /shopify/install first.');
            }
        }
        $this->store = $store;
    }

    public function shopify(): AdminClient
    {
        return $this->shopify ??= new AdminClient($this->store);
    }

    // ------------------------------------------------------------------
    // Intake
    // ------------------------------------------------------------------

    /**
     * Only paid orders may be shipped, so only they get an AWB.
     */
    public function isPaid(array $order): bool
    {
        return strtoupper((string) ($order['displayFinancialStatus'] ?? '')) === 'PAID';
    }

    /**
     * Ensure an airwaybill row exists for a Shopify order, routed from the
     * rate the shopper chose. Orders live on Shopify until the moment they
     * are shipped, so this is what brings one into the local database.
     */
    public function ensureForOrder(string $legacyOrderId): array
    {
        $existing = $this->awbs->findByOrderId($legacyOrderId);
        if ($existing !== null) {
            return $existing;
        }

        $order = $this->shopify()->getOrderForAwb($legacyOrderId);
        if ($order === null) {
            throw new RuntimeException("Shopify order {$legacyOrderId} not found.");
        }

        try {
            $this->awbs->insert([
                'order_id' => $legacyOrderId,
                'courier'  => $this->routeCourier($order),
                'status'   => AirwaybillModel::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            // order_id is unique, so a request that lost the race to create
            // the row gets a constraint violation here. That is the index
            // doing its job, not an error worth surfacing — the row it wanted
            // now exists. Anything else is a real failure and still raises.
            if ($this->awbs->findByOrderId($legacyOrderId) === null) {
                throw $e;
            }
        }

        return $this->awbs->findByOrderId($legacyOrderId);
    }

    /**
     * Internal shipment reference ('95' + Shopify order number), derived from
     * the live order — never stored.
     */
    public function numberId(array $order): string
    {
        return self::NUMBER_PREFIX . ltrim((string) $order['name'], '#');
    }

    /**
     * Courier routing.
     *
     * The shopper's choice reaches us as the order's shipping line, whose
     * `code` is the exact service_code RateEngine offered — so that is what
     * we route on. Click-and-collect still wins outright (in-store pickup is
     * a Ninja job regardless of the rate chosen), and an unrecognized code
     * falls back to the legacy title heuristic rather than silently
     * defaulting to JNE.
     */
    public function routeCourier(array $order): string
    {
        $title = (string) ($order['shippingLine']['title'] ?? '');
        $code  = (string) ($order['shippingLine']['code'] ?? '');

        // isClickAndCollect(), not a tag check of its own. This used to match
        // only the `clickncollect` tag while isClickAndCollect() also matched
        // the shipping-line title, so a pickup order that carried the title
        // but no tag was routed to JNE and then booked as a Ninja in-store
        // pickup — two halves of the app disagreeing about the same order.
        if ($this->isClickAndCollect($order)) {
            return AirwaybillModel::COURIER_NINJA;
        }

        $mapped = $this->couriers->serviceMap[$code]['courier'] ?? null;
        if ($mapped !== null) {
            return $mapped;
        }

        if ($code !== '' || $title !== '') {
            log_message('warning', 'Unmapped shipping line for order {name}: code="{code}" title="{title}" — falling back to title matching.', [
                'name'  => $order['name'] ?? '?',
                'code'  => $code,
                'title' => $title,
            ]);
        }

        if (str_contains($title, 'NINJA XPRESS')) {
            return AirwaybillModel::COURIER_NINJA;
        }
        if (str_contains($code, 'BDD-SPX') || str_contains($title, 'SPX')) {
            return AirwaybillModel::COURIER_SPX;
        }

        return AirwaybillModel::COURIER_JNE;
    }

    /**
     * Insurance threshold for this store, falling back to the config default.
     */
    private function insuranceMinCart(): int
    {
        return StoreModel::threshold($this->store, 'insurance_min_cart', 'insuranceMinCart');
    }

    /**
     * Whether this order's parcel is insured — the single answer all three
     * couriers book against.
     *
     * It has to agree with RateEngine::insurance(), which charges from the
     * threshold *inclusive* ($cartTotal < $minCart returns nothing). JNE
     * matched that; Ninja and SPX used a strict >, so a cart landing exactly
     * on the threshold was charged for insurance and then shipped without
     * it. One method now, so the three cannot drift apart again.
     */
    private function isInsured(float $cartTotal): bool
    {
        return $cartTotal >= $this->insuranceMinCart();
    }

    /**
     * The cart total the shopper was QUOTED on: every line at its original
     * unit price, times its quantity, in whole IDR.
     *
     * That is exactly what Shopify's rate request sums — items[].price is the
     * undiscounted unit price, discounts arrive separately — so it is the one
     * figure that decides insurance the same way at booking as it did at
     * checkout. Booking used the order total instead, which adds shipping and
     * subtracts discounts: near the threshold a shopper could pay for
     * insurance on a parcel then shipped without it, or the reverse.
     *
     * Falls back to the order subtotal when the lines carry no original price
     * (an order fetched with an older query shape).
     */
    public function quotedCartTotal(array $order): float
    {
        $total = 0.0;
        $known = false;

        foreach ($order['lineItems']['nodes'] ?? [] as $line) {
            $unit = $line['originalUnitPriceSet']['shopMoney']['amount'] ?? null;
            if ($unit === null) {
                continue;
            }
            $known = true;
            $total += (float) $unit * (int) ($line['quantity'] ?? 0);
        }

        return $known
            ? $total
            : (float) ($order['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0);
    }

    /**
     * SPX service_type for the rate the shopper chose (base_info.service_type).
     */
    public function spxServiceType(array $order): int
    {
        $code = (string) ($order['shippingLine']['code'] ?? '');
        $type = $this->couriers->serviceMap[$code]['spxServiceType'] ?? null;

        if ($type !== null) {
            return (int) $type;
        }

        // No confirmed service_type for this service, so it books as REGULER.
        // For HEMAT that means the shopper paid the cheaper economy price and
        // we bought the standard product — a real margin loss on every such
        // parcel. Logged rather than guessed: an invented service_type would
        // book the wrong product outright, which is worse than paying the
        // difference. See Config\Couriers::$serviceMap.
        log_message('warning', 'SPX service_type unconfirmed for "{code}" (order {name}) — booking as REGULER (1). The shopper paid the cheaper rate.', [
            'code' => $code,
            'name' => $order['name'] ?? '?',
        ]);

        return 1;
    }

    // ------------------------------------------------------------------
    // Generation (idempotent)
    // ------------------------------------------------------------------

    /**
     * Ensure an AWB exists for the given Shopify order id and return the
     * data needed to render its label. Never re-creates an existing AWB.
     */
    public function generate(int|string $legacyOrderId): array
    {
        $row = $this->awbs->findByOrderId($legacyOrderId);
        if ($row === null) {
            throw new RuntimeException("No airwaybill row for order {$legacyOrderId} — run intake first.");
        }

        $order = $this->shopify()->getOrderForAwb((string) $row['order_id']);
        if ($order === null) {
            throw new RuntimeException("Shopify order {$row['order_id']} not found.");
        }
        if (($order['displayFinancialStatus'] ?? '') !== 'PAID') {
            throw new RuntimeException("Order {$order['name']} is not paid ({$order['displayFinancialStatus']}).");
        }

        $numberId = $this->numberId($order);

        // Snapshot the order locally. Only orders we actually ship are stored;
        // every other view of orders is a live Shopify query.
        model(OrderModel::class)->upsertByOrderId(
            OrderModel::fromGraphqlNode($order, (int) $this->store['id'])
        );

        // Idempotency for ALL couriers: an existing waybill is never re-generated.
        if (empty($row['waybill'])) {
            // The empty-waybill check above is not enough on its own: two
            // presses of Generate AWB arriving together both pass it, and both
            // then book a real parcel. Only one request can win this claim.
            if (! $this->awbs->claimForBooking((int) $row['id'])) {
                throw new RuntimeException(
                    "Order {$order['name']} is already being booked by another request. "
                    . 'Refresh the Orders page in a moment to see the waybill.',
                );
            }

            try {
                $row = MockMode::enabled()
                    // Mock: invent the waybill locally, skip the courier API.
                    ? $this->generateMock($row, $numberId)
                    : match ($row['courier']) {
                        AirwaybillModel::COURIER_NINJA => $this->generateNinja($row, $order, $numberId),
                        AirwaybillModel::COURIER_SPX   => $this->generateSpx($row, $order),
                        AirwaybillModel::COURIER_GRAB  => $this->generateGrab($row, $order),
                        default                        => $this->generateJne($row, $order, $numberId),
                    };
            } catch (Throwable $e) {
                // Mock bookings touch no courier, so a failure there cannot
                // have created a shipment and the claim is safe to hand back
                // immediately.
                //
                // A real booking is different: the courier clients report a
                // lost response and a rejected request identically, so a
                // failure here may mean a parcel exists that we have no
                // waybill for. Releasing the claim would let the next press
                // book a second one. It is left to expire instead — five
                // minutes to check the courier beats a duplicate shipment.
                if (MockMode::enabled()) {
                    $this->awbs->releaseBooking((int) $row['id']);
                }

                throw $e;
            }
        }

        // Outside the block on purpose: a row that already has a waybill but
        // was never fulfilled (an earlier failure, or a waybill created before
        // fulfillment was wired up) is repaired on the next press. fulfill()
        // is itself idempotent, so an already-fulfilled row is a no-op.
        $issue = $this->fulfill($row);

        return $this->labelData($row, $order) + ['fulfillment_issue' => $issue];
    }

    /**
     * Mock generation: a locally-invented waybill so the pipeline and labels
     * can be exercised without booking a shipment. Waybills are prefixed
     * MOCK- so they are impossible to confuse with a real one. The Shopify
     * order IS still fulfilled (with the mock tracking number) so the flow
     * can be verified end to end — see fulfill(), which suppresses the
     * customer notification while mock mode is on.
     */
    private function generateMock(array $row, string $numberId): array
    {
        $courier = strtoupper((string) $row['courier']);
        $waybill = "MOCK-{$courier}-{$numberId}";

        $fields = ['waybill' => $waybill];
        if ($row['courier'] === AirwaybillModel::COURIER_SPX) {
            $fields['spx_order_ref']   = 'MOCK-SPXID-' . $numberId;
            $fields['sort_code']       = 'MOCK-SC';
            $fields['third_sort_code'] = 'MOCK-ZONE';
        }

        $this->awbs->update($row['id'], $fields);

        log_message('info', 'Mock AWB {waybill} created for order {order} (mock mode is on — no courier call; Shopify is still fulfilled, without notifying the customer).', [
            'waybill' => $waybill,
            'order'   => $row['order_id'],
        ]);

        return $this->awbs->find($row['id']);
    }

    private function generateNinja(array $row, array $order, string $numberId): array
    {
        $addr    = $order['shippingAddress'];
        $isCnc   = $this->isClickAndCollect($order);
        $address = $isCnc
            ? 'TOKO EXECUTIVE ' . strtoupper(trim(($addr['address2'] ?? '') . ' ' . ($addr['address1'] ?? '')))
            : strtoupper((string) $addr['address1']);

        $windows = $this->ninjaWindows();
        // Insured on the quoted cart total, and declared at it: the shopper's
        // insurance fee was computed on exactly that figure.
        $cart    = $this->quotedCartTotal($order);
        $insured = $this->isInsured($cart) ? (int) ceil($cart) : 0;
        $waybill = $this->couriers->ninjaWaybillPrefix . $numberId;

        $response = (new NinjaClient($this->couriers))->createOrder([
            'waybill'        => $waybill,
            'name'           => $addr['name'],
            'phone'          => $this->normalizePhone((string) $addr['phone']),
            'email'          => (string) ($order['email'] ?? ''),
            'address'        => trim(preg_replace('/\s+/', ' ', $address)),
            'subdistrict'    => (string) ($addr['address2'] ?? ''),
            'city'           => (string) $addr['city'],
            'province'       => (string) $addr['province'],
            'zip'            => (string) $addr['zip'],
            'qty'            => $this->totalQty($order),
            'weight_kg'      => $this->weightKg($order),
            'pickup_date'    => $windows['pickup_date'],
            'pickup_start'   => $windows['pickup_start'],
            'pickup_end'     => $windows['pickup_end'],
            'delivery_date'  => $windows['delivery_date'],
            'delivery_start' => $windows['delivery_start'],
            'delivery_end'   => $windows['delivery_end'],
            'insured_value'  => $insured,
        ]);

        // Ninja assigns the requested tracking number; treat explicit API
        // rejection (other than duplicate tracking number) as fatal.
        $error = $response['error']['message'] ?? null;
        if ($error !== null && stripos($error, 'duplicate') === false) {
            throw new RuntimeException("Ninja order creation failed: {$error}");
        }

        $this->awbs->update($row['id'], ['waybill' => $waybill]);

        return $this->awbs->find($row['id']);
    }

    private function generateSpx(array $row, array $order): array
    {
        $addr  = $order['shippingAddress'];
        $isCnc = $this->isClickAndCollect($order);

        $address = $isCnc
            ? $this->stripBrackets(strtoupper(($addr['address2'] ?? '') . ' ' . ($addr['address1'] ?? '')))
            : $this->stripBrackets((string) $addr['address1']);

        $cart    = $this->quotedCartTotal($order);
        $insured = $this->isInsured($cart);

        $serviceType = $this->spxServiceType($order);

        $spx  = new SpxClient($this->couriers);
        $slot = $spx->firstPickupSlot($serviceType);
        if ($slot === null) {
            throw new RuntimeException('No SPX pickup slot available.');
        }

        $district = $this->firstPart($this->convertBrackets((string) ($addr['address2'] ?? '')));
        $dest     = (new WidgetProxyClient($this->couriers))->spxDestination($district, (string) $addr['zip']);
        if (empty($dest['city'])) {
            throw new RuntimeException("SPX destination could not be resolved for zip {$addr['zip']}.");
        }

        $shipment = [
            'order_ref'      => 'SPXID' . $order['name'],
            'name'           => $addr['name'],
            'phone'          => $this->normalizePhone((string) $addr['phone']),
            'email'          => (string) ($order['email'] ?? ''),
            'address'        => trim(preg_replace('/\s+/', ' ', $address)),
            'district'       => $this->stripBrackets((string) $dest['district']),
            'city'           => (string) $dest['city'],
            'province'       => (string) $dest['prov'],
            'zip'            => (string) $addr['zip'],
            'qty'            => $this->totalQty($order),
            'weight_kg'      => $this->weightKg($order),
            'subtotal'       => $insured ? (int) ceil($cart) : 0,
            'insurance_fee'  => $insured ? (new RateEngine())->insurance('spx', $cart, $this->insuranceMinCart()) : 0,
            'insurance_flag' => $insured ? 1 : 0,
            'pickup'         => $slot,
            'service_type'   => $serviceType,
        ];

        $orderResult = self::spxBookedOrder($spx->createOrder($shipment), $shipment['order_ref']);

        $this->awbs->update($row['id'], [
            'waybill'         => $orderResult['tracking_no'],
            'spx_order_ref'   => $shipment['order_ref'],
            'sort_code'       => $orderResult['r_first_sort_code'] ?? null,
            'third_sort_code' => $orderResult['r_third_sort_code'] ?? null,
        ]);

        return $this->awbs->find($row['id']);
    }

    /**
     * The booked order from SPX's create-order reply, or an exception.
     *
     * "order id has been used" is NOT a reason to book again under another
     * reference, which is what this used to do. It is the reply SPX gives when
     * an earlier attempt for this order already succeeded — typically one whose
     * response was lost, the very case the booking claim is left to expire
     * for — so re-booking shipped a second parcel. It now stops, and says what
     * to check.
     *
     * @return array<string, mixed> the orders[0] entry, carrying tracking_no
     */
    public static function spxBookedOrder(?array $response, string $orderRef): array
    {
        $failure = (string) ($response['data']['fail_list'][0]['message'] ?? '');

        if (str_contains($failure, 'order id has been used')) {
            throw new RuntimeException(
                "SPX already has an order {$orderRef} — most likely booked by an earlier attempt whose reply was lost. "
                . 'No second parcel was booked. Find its tracking number in the SPX portal before trying anything else.',
            );
        }

        $booked = $response['data']['orders'][0] ?? null;
        if (! is_array($booked) || empty($booked['tracking_no'])) {
            throw new RuntimeException('SPX order creation failed: ' . json_encode($response));
        }

        return $booked;
    }

    private function generateJne(array $row, array $order, string $numberId): array
    {
        $addr = $order['shippingAddress'];

        $destination = (new WidgetProxyClient($this->couriers))->jneDestination((string) $addr['zip'])['code_destination'] ?? null;
        if ($destination === null) {
            throw new RuntimeException("JNE destination code not found for zip {$addr['zip']}.");
        }

        $isCod = ($order['shippingLine']['code'] ?? '') === 'COD';
        $total = (float) $order['currentTotalPriceSet']['shopMoney']['amount'];

        // Sanitize + split address into JNE's three 30-char lines.
        $fullAddress = preg_replace('/[^A-Za-z0-9-]+/', ' ', str_replace('%', '-', ($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? '')));
        $lines       = str_split(trim($fullAddress), 30);

        $cart    = $this->quotedCartTotal($order);
        $insured = $this->isInsured($cart);

        $response = (new JneClient($this->couriers))->generateCnote([
            'order_id'          => $numberId,
            'receiver_name'     => trim(($addr['firstName'] ?? '') . ' ' . ($addr['lastName'] ?? '')),
            'receiver_addr1'    => $lines[0] ?? '',
            'receiver_addr2'    => $lines[1] ?? '',
            'receiver_addr3'    => $lines[2] ?? '',
            'receiver_city'     => (string) $addr['city'],
            'receiver_province' => (string) $addr['province'],
            'receiver_zip'      => (string) $addr['zip'],
            'receiver_phone'    => $this->normalizePhone((string) $addr['phone']),
            'qty'               => $this->totalQty($order),
            'weight'            => $this->jneWeightKg($order),
            'goods_desc'        => 'CLOTHING',
            // Declared at the quoted cart total the insurance was paid on;
            // COD below still collects the whole order.
            'goods_value'       => $insured ? (int) ceil($cart) : 0,
            'insurance_flag'    => $insured ? 'Y' : 'N',
            'destination'       => $destination,
            'service'           => 'REG',
            'cod_flag'          => $isCod ? 'YES' : 'N',
            'account'           => $isCod ? $this->couriers->jneCodAccount : $this->couriers->jneRegAccount,
            'cod_amount'        => $isCod ? (int) $total : 0,
        ]);

        $cnote = $response['detail'][0]['cnote_no'] ?? null;
        if ($cnote === null) {
            throw new RuntimeException('JNE generatecnote failed: ' . json_encode($response));
        }

        $this->awbs->update($row['id'], ['waybill' => $cnote]);

        return $this->awbs->find($row['id']);
    }

    /**
     * Book a GrabExpress instant delivery.
     *
     * Unlike the parcel couriers, Grab prices on coordinates: the exact pin the
     * shopper dropped, carried onto the order as the "coordinates" custom
     * attribute, and falling back to geocoding the address when it is absent —
     * the same source the rate used. Grab returns a delivery id (stored as the
     * waybill) and a tracking URL.
     */
    private function generateGrab(array $row, array $order): array
    {
        $addr = $order['shippingAddress'];

        [$lat, $lng] = $this->grabDropCoordinates($order);
        if ($lat === null) {
            throw new RuntimeException(
                'No drop-off coordinates for GrabExpress: the order has no "coordinates" '
                . 'attribute and its address could not be geocoded.',
            );
        }

        $address = $this->stripBrackets((string) ($addr['address1'] ?? ''));
        $response = (new GrabClient($this->couriers))->createDelivery([
            'merchantOrderID' => (string) $order['name'],
            // The same package value the quote declared to Grab.
            'cartTotal'       => (int) $this->quotedCartTotal($order),
            'weightKg'        => $this->weightKg($order),
            'dropAddress'     => trim(preg_replace('/\s+/', ' ', $address)) ?: (string) ($addr['city'] ?? ''),
            'dropLat'         => $lat,
            'dropLng'         => $lng,
            'recipientFirst'  => (string) ($addr['firstName'] ?? $addr['name'] ?? ''),
            'recipientLast'   => (string) ($addr['lastName'] ?? ''),
            'recipientPhone'  => $this->normalizePhone((string) ($addr['phone'] ?? '')),
            'recipientEmail'  => (string) ($order['email'] ?? ''),
        ]);

        $deliveryId = $response['deliveryID'] ?? null;
        if ($deliveryId === null) {
            throw new RuntimeException('GrabExpress create delivery failed: ' . json_encode($response));
        }

        $this->awbs->update($row['id'], [
            'waybill'      => $deliveryId,
            'tracking_url' => (string) ($response['trackingURL'] ?? ''),
        ]);

        return $this->awbs->find($row['id']);
    }

    /**
     * The drop-off coordinates for a Grab booking: the order's "coordinates"
     * custom attribute (the exact pin), else the geocoded shipping address.
     *
     * @return array{0: float|null, 1: float|null} [lat, lng], or [null, null]
     */
    private function grabDropCoordinates(array $order): array
    {
        foreach ($order['customAttributes'] ?? [] as $attr) {
            if (($attr['key'] ?? '') === 'coordinates' && ! empty($attr['value'])) {
                $parts = array_map('trim', explode(',', (string) $attr['value']));
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    return [(float) $parts[0], (float) $parts[1]];
                }
            }
        }

        // No pin on the order — geocode the address, as the rate does.
        $addr    = $order['shippingAddress'] ?? [];
        $address = implode(', ', array_filter([
            $this->stripBrackets((string) ($addr['address1'] ?? '')),
            (string) ($addr['address2'] ?? ''),
            (string) ($addr['city'] ?? ''),
            (string) ($addr['province'] ?? ''),
            (string) ($addr['zip'] ?? ''),
        ]));

        $coords = (new GeocodeClient($this->couriers))->geocode($address);

        return $coords ?? [null, null];
    }

    // ------------------------------------------------------------------
    // Fulfillment + tracking
    // ------------------------------------------------------------------

    public function fulfill(array $row): ?string
    {
        if ((int) $row['status'] === AirwaybillModel::STATUS_FULFILLED || empty($row['waybill'])) {
            return null;
        }

        [$company, $url] = match ($row['courier']) {
            AirwaybillModel::COURIER_NINJA => ['Ninja Xpress', 'https://www.ninjaxpress.co/en-id/tracking?id=' . $row['waybill']],
            // Grab returns a per-delivery tracking URL at booking; fall back to
            // Grab's help page when it is not yet populated (status ALLOCATING).
            AirwaybillModel::COURIER_GRAB  => ['GrabExpress', ($row['tracking_url'] ?? '') ?: 'https://www.grab.com/id/express/'],
            // No waybill appended: the old URL built `…/track?SPXID123`, a
            // query string with no parameter name, which SPX's tracker (a
            // single-page app) ignores — it opened an empty search box while
            // looking like a deep link. SPX publishes no documented deep-link
            // parameter, so this points at their official tracking page
            // rather than inventing one. Shopify shows the number beside it.
            AirwaybillModel::COURIER_SPX   => ['Shopee Xpress', 'https://spx.co.id/en/track'],
            AirwaybillModel::COURIER_LJR   => ['LJR Logistics', 'https://ljrlogistics.com'],
            default                        => ['JNE', 'https://www.jne.co.id/en/tracking/trace'],
        };

        // Mock mode still fulfills, so the end-to-end flow is testable — but
        // it never emails the customer about a shipment that does not exist.
        // In live mode this is what sends the shipping confirmation.
        $notify = ! MockMode::enabled();

        try {
            $result = $this->shopify()->fulfillWithTracking(
                (string) $row['order_id'], $company, $row['waybill'], $url, $notify
            );
        } catch (\Throwable $e) {
            log_message('error', 'Fulfillment failed for order {ref}: {msg}', ['ref' => $row['order_id'], 'msg' => $e->getMessage()]);

            return 'Shopify fulfilment failed: ' . $e->getMessage();
        }

        // No fulfillment id means Shopify created nothing — most often the
        // order has no open fulfillment order (already fulfilled elsewhere,
        // or on hold). Marking it fulfilled here would claim the customer was
        // notified when no email was ever sent.
        if (empty($result['id'])) {
            $reason = $result['skipped'] ?? 'no fulfillment was created';
            log_message('warning', 'Fulfillment skipped for order {ref}: {why}', ['ref' => $row['order_id'], 'why' => $reason]);

            return 'Shopify did not fulfil the order (' . $reason . ') — the customer was not notified.';
        }

        $this->awbs->update($row['id'], ['status' => AirwaybillModel::STATUS_FULFILLED]);

        return null;
    }

    /**
     * Poll couriers for pending shipments and fulfill confirmed ones.
     * Returns the number of newly fulfilled orders.
     */
    /**
     * Poll courier tracking for shipments that have a waybill but are not
     * fulfilled yet, and log which ones are moving or delivered.
     *
     * Read-only by design: fulfillment is always manual, performed by the
     * Generate AWB button in the admin. This never writes to Shopify.
     *
     * @return int number of shipments confirmed as moving/delivered
     */
    public function track(int $windowDays = 3): int
    {
        if (MockMode::enabled()) {
            log_message('info', 'awb:track skipped — mock mode is on.');

            return 0;
        }

        $confirmed = 0;

        foreach ($this->awbs->pendingSince($windowDays) as $row) {
            $moving = match ($row['courier']) {
                AirwaybillModel::COURIER_NINJA => $this->ninjaDelivered($row['waybill']),
                default                        => $this->jneMoving($row['waybill']),
            };

            if ($moving) {
                log_message('info', 'AWB {waybill} ({courier}) for order {order} is moving at the courier.', [
                    'waybill' => $row['waybill'],
                    'courier' => $row['courier'],
                    'order'   => $row['order_id'],
                ]);
                $confirmed++;
            }
        }

        return $confirmed;
    }

    private function ninjaDelivered(string $awb): bool
    {
        $status = (new NinjaClient($this->couriers))->track($awb)['status'] ?? '';

        return strcasecmp($status, 'Delivered') === 0;
    }

    private function jneMoving(string $awb): bool
    {
        $trace = (new JneClient($this->couriers))->trace($awb);

        return ! empty($trace['history'] ?? $trace['cnote'] ?? null);
    }

    // ------------------------------------------------------------------
    // Label data
    // ------------------------------------------------------------------

    public function labelData(array $row, array $order): array
    {
        $addr  = $order['shippingAddress'];
        $isCnc = $this->isClickAndCollect($order);

        return [
            'courier'    => $row['courier'],
            'awb'        => $row['waybill'],
            'number_id'  => $this->numberId($order),
            'order_name' => $order['name'],
            'status'     => $isCnc ? 'cnc' : 'reguler',
            'consignee'  => (string) $addr['name'],
            'address1'   => (string) $addr['address1'],
            'address2'   => (string) ($addr['address2'] ?? ''),
            'city'       => (string) $addr['city'],
            'province'   => (string) $addr['province'],
            'zip'        => (string) $addr['zip'],
            'phone'      => (string) $addr['phone'],
            'quantity'   => $this->totalQty($order),
            'weight_kg'  => $this->weightKg($order),
            'amount'     => (float) $order['currentTotalPriceSet']['shopMoney']['amount'],
            'service'    => ($order['shippingLine']['code'] ?? '') === 'COD' ? 'COD' : 'REG',
            'sort_code'  => $row['sort_code'] ?? null,
            'zone_name'  => $row['third_sort_code'] ?? null,
            'items'      => array_map(static fn ($item) => [
                'sku'   => $item['sku'],
                'title' => $item['title'],
                'qty'   => $item['quantity'],
            ], $order['lineItems']['nodes'] ?? []),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function isClickAndCollect(array $order): bool
    {
        $tags  = is_array($order['tags'] ?? null) ? implode(',', $order['tags']) : (string) ($order['tags'] ?? '');
        $title = (string) ($order['shippingLine']['title'] ?? '');

        return stripos($tags, 'clickncollect') !== false
            || stripos($title, 'Clik and Collect') !== false
            || stripos($title, 'Click and Collect') !== false;
    }

    private function totalQty(array $order): int
    {
        return array_sum(array_column($order['lineItems']['nodes'] ?? [], 'quantity')) ?: 1;
    }

    /**
     * Weight in whole kg for Ninja/SPX. Same rule as the quote — it used to
     * round(), so a 1.4 kg parcel was declared as 1 kg.
     */
    private function weightKg(array $order): int
    {
        return RateEngine::billableWeightKg($this->weightGrams($order));
    }

    /**
     * JNE weight tiers with 200g tolerance. The legacy version had strict
     * comparisons leaving gaps at the exact boundaries; <= closes them.
     */
    private function jneWeightKg(array $order): int
    {
        // Was a tiered rule with a 0.2 kg grace band, which declared 1 kg for
        // a 1.15 kg parcel the shopper had already been charged 2 kg for.
        return RateEngine::billableWeightKg($this->weightGrams($order));
    }

    private function weightGrams(array $order): int
    {
        $grams = (int) ($order['totalWeight'] ?? 0);
        if ($grams > 0) {
            return $grams;
        }

        foreach ($order['lineItems']['nodes'] ?? [] as $item) {
            $weight = $item['variant']['inventoryItem']['measurement']['weight'] ?? null;
            if ($weight === null) {
                continue;
            }
            $value = (float) $weight['value'];
            $grams += (int) match ($weight['unit']) {
                'KILOGRAMS' => $value * 1000,
                'POUNDS'    => $value * 453.592,
                'OUNCES'    => $value * 28.3495,
                default     => $value, // GRAMS
            } * (int) $item['quantity'];
        }

        return $grams;
    }

    /**
     * Ninja pickup/delivery windows by hour of day (legacy schedule).
     */
    private function ninjaWindows(): array
    {
        $hour = (int) date('H');

        if ($hour >= 21) {
            return [
                'pickup_date'    => date('Y-m-d', strtotime('+1 day')),
                'pickup_start'   => '09:00',
                'pickup_end'     => '12:00',
                'delivery_date'  => date('Y-m-d', strtotime('+2 day')),
                'delivery_start' => '09:00',
                'delivery_end'   => '18:00',
            ];
        }

        return [
            'pickup_date'    => date('Y-m-d'),
            'pickup_start'   => $hour < 12 ? '12:00' : '18:00',
            'pickup_end'     => $hour < 12 ? '15:00' : '22:00',
            'delivery_date'  => date('Y-m-d', strtotime('+1 day')),
            'delivery_start' => '09:00',
            'delivery_end'   => '18:00',
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = str_replace(['-', ' '], '', trim($phone));
        if (str_starts_with($phone, '+62')) {
            return '0' . substr($phone, 3);
        }
        if (str_starts_with($phone, '62')) {
            return '0' . substr($phone, 2);
        }
        if ($phone !== '' && $phone[0] !== '0') {
            return '0' . $phone;
        }

        return $phone;
    }

    private function convertBrackets(string $value): string
    {
        return preg_replace('/\[\[(.*?)\]\]/', '($1)', $value);
    }

    private function stripBrackets(string $value): string
    {
        return trim(str_replace(['[[', ']]', '"', "'"], ' ', $value));
    }

    private function firstPart(string $value): string
    {
        $parts = preg_split('/[-,]/', $value);

        return trim($parts[0] ?? $value);
    }
}

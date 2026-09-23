<?php

namespace App\Libraries\Tracking;

use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\JneClient;
use App\Libraries\Couriers\LjrClient;
use App\Libraries\Couriers\NinjaClient;
use App\Models\AirwaybillModel;
use Config\Couriers as CouriersConfig;
use DateTimeImmutable;

/**
 * Courier tracking, normalized.
 *
 * Every courier answers a different question in a different shape: JNE returns
 * a cnote plus a scan history, Ninja a status word (and, separately, a scan
 * list), LJR a table of status rows, Grab a state machine with a timeline, and
 * SPX nothing at all. This turns whichever of those replies into one structure
 * a single view can render, and never throws: a shopper is waiting on the page,
 * so a courier that is down degrades to "no scans yet" rather than a stack trace.
 *
 * Strictly read-only. It polls couriers and formats what they say; it writes
 * nothing to the database and nothing to Shopify. Fulfillment in this app is
 * always the operator pressing Generate AWB.
 */
class TrackingService
{
    // ------------------------------------------------------------------
    // Normalized stages — the vocabulary the view renders
    // ------------------------------------------------------------------
    public const STAGE_BOOKED            = 'booked';
    public const STAGE_PICKED_UP         = 'picked_up';
    public const STAGE_IN_TRANSIT        = 'in_transit';
    public const STAGE_OUT_FOR_DELIVERY  = 'out_for_delivery';
    public const STAGE_DELIVERED         = 'delivered';
    public const STAGE_EXCEPTION         = 'exception';

    /** Display order of the progress bar. Exceptions sit outside it. */
    public const STAGE_FLOW = [
        self::STAGE_BOOKED,
        self::STAGE_PICKED_UP,
        self::STAGE_IN_TRANSIT,
        self::STAGE_OUT_FOR_DELIVERY,
        self::STAGE_DELIVERED,
    ];

    public const STAGE_LABELS = [
        self::STAGE_BOOKED           => 'Shipment booked',
        self::STAGE_PICKED_UP        => 'Picked up',
        self::STAGE_IN_TRANSIT       => 'In transit',
        self::STAGE_OUT_FOR_DELIVERY => 'Out for delivery',
        self::STAGE_DELIVERED        => 'Delivered',
        self::STAGE_EXCEPTION        => 'Needs attention',
    ];

    public const COURIER_NAMES = [
        AirwaybillModel::COURIER_JNE   => 'JNE',
        AirwaybillModel::COURIER_NINJA => 'Ninja Xpress',
        AirwaybillModel::COURIER_SPX   => 'Shopee Xpress',
        AirwaybillModel::COURIER_LJR   => 'LJR Logistics',
        AirwaybillModel::COURIER_GRAB  => 'GrabExpress',
    ];

    /**
     * How long a courier's answer is reused, in seconds.
     *
     * Long enough that a shopper hammering refresh cannot turn into a burst
     * of upstream calls; short enough that a scan shows up within minutes.
     */
    public const CACHE_TTL = 300;

    /**
     * How long the last good answer is kept as a fallback, in seconds.
     *
     * A courier that times out or errors used to leave the shopper looking at
     * "no scans yet" for a parcel that had them. Scans never disappear, so an
     * empty answer after a good one means the courier is down, not that the
     * parcel went backwards — and the last answer is shown instead, marked
     * stale. A week comfortably outlives any delivery this app books.
     */
    public const LAST_KNOWN_TTL = 604800;

    /**
     * Phrases that place a scan on the timeline, checked in this order.
     *
     * Order is the whole design: "RECEIVED AT SORTING CENTER" contains both a
     * pickup word and a transit word, and only the sequence below reads it as
     * transit. Matching is substring, case-insensitive, over the scan text.
     */
    private const STAGE_KEYWORDS = [
        // Deliberately narrow. A bare 'received' (or its Indonesian 'diterima')
        // also appears in JNE's pickup scan, "SHIPMENT RECEIVED BY JNE COUNTER
        // OFFICER", and in warehouse scans — and showing a parcel as delivered
        // the hour it was collected is the one error this page must not make.
        // Only wording that can mean nothing but a completed delivery is here.
        self::STAGE_DELIVERED => [
            'delivered', 'terkirim', 'completed', 'selesai',
            'telah diterima', 'diterima oleh',
        ],
        self::STAGE_EXCEPTION => [
            'return', 'retur', 'failed', 'gagal', 'cancel', 'batal', 'undelivered',
            'not at home', 'problem', 'exception', 'lost', 'damaged', 'rusak', 'hilang',
        ],
        self::STAGE_OUT_FOR_DELIVERY => [
            'out for delivery', 'with delivery courier', 'on delivery', 'on vehicle',
            'dalam pengiriman', 'sedang diantar', 'delivery process', 'in_delivery',
        ],
        self::STAGE_IN_TRANSIT => [
            'transit', 'departed', 'arrived', 'sorting', 'hub', 'on process',
            'dalam proses', 'processed', 'origin gateway', 'destination gateway',
        ],
        self::STAGE_PICKED_UP => [
            'picked up', 'pickup', 'pick up', 'shipment received', 'diambil',
            'collected', 'allocating', 'assigned',
        ],
    ];

    /**
     * The mock timeline, as [hours after booking, stage, text, place].
     *
     * Mock mode is the default, so without this the tracking page would be a
     * permanently empty box on every environment that has not gone live —
     * untestable, and impossible to demo. Steps appear as their hour passes,
     * so a mock parcel visibly moves over its first two days.
     */
    private const MOCK_STEPS = [
        [0,  self::STAGE_BOOKED,           'Shipment booked — waybill created', 'Bekasi Warehouse'],
        [3,  self::STAGE_PICKED_UP,        'Picked up by :courier',             'Bekasi Warehouse'],
        [10, self::STAGE_IN_TRANSIT,       'Departed origin sorting centre',    'Bekasi Hub'],
        [22, self::STAGE_IN_TRANSIT,       'Arrived at destination hub',        ':destination'],
        [32, self::STAGE_OUT_FOR_DELIVERY, 'Out for delivery',                  ':destination'],
        [44, self::STAGE_DELIVERED,        'Delivered to recipient',            ':destination'],
    ];

    private CouriersConfig $config;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config('Couriers');
    }

    /**
     * Trace one airwaybill row.
     *
     * @param array<string, mixed> $row     an airwaybills record
     * @param array<string, mixed> $context optional 'destination' (city) used
     *                                      to label mock scans
     *
     * @return array{courier:string, courierName:string, waybill:string,
     *               trackingUrl:string, stage:string, stageLabel:string,
     *               events:list<array{at:?string, description:string, location:string, stage:string}>,
     *               source:string, checkedAt:string, stale:bool, note:?string}
     *
     *               checkedAt is when these scans were fetched from the
     *               courier — not when this page was drawn — and stale is true
     *               when the courier did not answer and they are the last
     *               ones received.
     */
    public function track(array $row, array $context = []): array
    {
        $courier = (string) ($row['courier'] ?? AirwaybillModel::COURIER_JNE);
        $waybill = (string) ($row['waybill'] ?? '');

        $collected = $this->collect($courier, $waybill, $row, $context);
        [$events, $source, $note] = $collected;

        // Newest first, and stable for scans a courier stamped to the same
        // minute — usort alone would shuffle those on every request.
        $events = $this->sortNewestFirst($events);

        return [
            'courier'     => $courier,
            'courierName' => self::courierName($courier),
            'waybill'     => $waybill,
            'trackingUrl' => $this->trackingUrl($courier, $row),
            'stage'       => $this->overallStage($events),
            'stageLabel'  => self::STAGE_LABELS[$this->overallStage($events)],
            'events'      => $events,
            'source'      => $source,
            'checkedAt'   => $collected['checkedAt'],
            'stale'       => $collected['stale'],
            'note'        => $note,
        ];
    }

    public static function courierName(string $courier): string
    {
        return self::COURIER_NAMES[$courier] ?? strtoupper($courier);
    }

    // ------------------------------------------------------------------
    // Collection
    // ------------------------------------------------------------------

    /**
     * @return array{0: list<array<string, mixed>>, 1: string, 2: ?string, checkedAt: string, stale: bool}
     *         [events, source, note] plus when they were fetched
     */
    private function collect(string $courier, string $waybill, array $row, array $context): array
    {
        $now = date('Y-m-d H:i:s');

        if ($waybill === '') {
            return [[], 'unavailable', 'This order has no waybill yet.', 'checkedAt' => $now, 'stale' => false];
        }

        // Keyed on the waybill rather than the courier row so that a mock and
        // a real shipment can never share an entry.
        if (str_starts_with($waybill, 'MOCK-')) {
            return [$this->mockEvents($courier, $row, $context), 'mock', null, 'checkedAt' => $now, 'stale' => false];
        }

        $key    = md5($courier . '|' . $waybill);
        $cache  = service('cache');
        $cached = $cache->get('track_' . $key);

        if (is_array($cached)) {
            return [$cached['events'], $cached['source'], $cached['note'],
                'checkedAt' => $cached['checkedAt'] ?? $now, 'stale' => false];
        }

        $result = match ($courier) {
            AirwaybillModel::COURIER_NINJA => $this->ninjaEvents($waybill),
            AirwaybillModel::COURIER_LJR   => $this->ljrEvents($waybill),
            AirwaybillModel::COURIER_GRAB  => $this->grabEvents($waybill),
            AirwaybillModel::COURIER_SPX   => [[], 'unavailable', 'Shopee Xpress does not publish a tracking API — use the button below to check on their site.'],
            default                        => $this->jneEvents($waybill),
        };

        // Only a real answer is cached. Caching a courier outage would keep
        // showing "no scans" for five minutes after the courier recovered.
        if ($result[0] !== []) {
            $answer = ['events' => $result[0], 'source' => $result[1], 'note' => $result[2], 'checkedAt' => $now];
            $cache->save('track_' . $key, $answer, self::CACHE_TTL);
            $cache->save('track_last_' . $key, $answer, self::LAST_KNOWN_TTL);

            return $result + ['checkedAt' => $now, 'stale' => false];
        }

        // Nothing now, but scans before: the courier is down or slow, not the
        // parcel. Show what it last said, and say that it is the last word.
        $last = $cache->get('track_last_' . $key);

        if (is_array($last) && ($last['events'] ?? []) !== []) {
            $when = date('j M Y H:i', strtotime((string) $last['checkedAt']) ?: time());

            return [$last['events'], $last['source'],
                "The courier did not answer just now — these are the last scans received, at {$when}.",
                'checkedAt' => $last['checkedAt'], 'stale' => true];
        }

        return $result + ['checkedAt' => $now, 'stale' => false];

        return $result;
    }

    /** @return array{0: list<array<string, mixed>>, 1: string, 2: ?string} */
    private function jneEvents(string $waybill): array
    {
        try {
            $trace = $this->jne()->trace($waybill);
        } catch (\Throwable $e) {
            log_message('error', 'JNE trace failed for {awb}: {msg}', ['awb' => $waybill, 'msg' => $e->getMessage()]);
            $trace = null;
        }

        if (! is_array($trace) || ! empty($trace['error'])) {
            return [[], 'unavailable', null];
        }

        $events = [];

        foreach ($trace['history'] ?? [] as $scan) {
            if (! is_array($scan)) {
                continue;
            }
            $text = trim((string) ($scan['desc'] ?? ''));
            if ($text === '') {
                continue;
            }
            $events[] = $this->event($scan['date'] ?? null, $text, $this->bracketed($text));
        }

        // A cnote with a POD date but no history row for it still means the
        // parcel arrived; JNE sometimes reports the two separately.
        $pod = trim((string) ($trace['cnote']['pod_status'] ?? ''));
        if ($pod !== '' && $events === []) {
            $events[] = $this->event(
                $trace['cnote']['cnote_pod_date'] ?? ($trace['cnote']['cnote_date'] ?? null),
                $pod,
                (string) ($trace['cnote']['cnote_destination'] ?? ''),
            );
        }

        return [$events, $events === [] ? 'unavailable' : 'courier', null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: string, 2: ?string} */
    private function ninjaEvents(string $waybill): array
    {
        $client = $this->ninja();

        try {
            $history = $client->history($waybill);
        } catch (\Throwable $e) {
            log_message('error', 'Ninja history failed for {awb}: {msg}', ['awb' => $waybill, 'msg' => $e->getMessage()]);
            $history = null;
        }

        $scans = [];
        if (is_array($history)) {
            // The proxy has wrapped this payload differently over time; take
            // the first shape that actually holds a list of scans.
            $scans = $history['events'] ?? $history['data']['events'] ?? $history['tracking'] ?? $history['data'] ?? $history;
        }

        $events = [];
        foreach (is_array($scans) ? $scans : [] as $scan) {
            if (! is_array($scan)) {
                continue;
            }
            $text = trim((string) ($scan['status'] ?? $scan['description'] ?? $scan['comments'] ?? ''));
            if ($text === '') {
                continue;
            }
            $events[] = $this->event(
                $scan['timestamp'] ?? $scan['date'] ?? $scan['created_at'] ?? null,
                $text,
                (string) ($scan['hub'] ?? $scan['hub_name'] ?? $scan['location'] ?? ''),
            );
        }

        if ($events !== []) {
            return [$events, 'courier', null];
        }

        // No scan list: fall back to the single status word, which is what
        // the fulfillment cron reads. Better one dated-unknown line than none.
        try {
            $status = trim((string) (($client->track($waybill) ?? [])['status'] ?? ''));
        } catch (\Throwable $e) {
            $status = '';
        }

        if ($status === '') {
            return [[], 'unavailable', null];
        }

        return [[$this->event(null, $status, '')], 'courier', null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: string, 2: ?string} */
    private function ljrEvents(string $waybill): array
    {
        $events = [];

        foreach ($this->ljr()->track($waybill) as $scan) {
            $text = trim((string) ($scan['statusName'] ?? $scan['status'] ?? ''));
            if ($text === '') {
                continue;
            }
            $events[] = $this->event(
                $scan['statusDate'] ?? $scan['createdDate'] ?? $scan['created_date'] ?? null,
                $text,
                (string) ($scan['location'] ?? $scan['cityName'] ?? ''),
            );
        }

        return [$events, $events === [] ? 'unavailable' : 'courier', null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: string, 2: ?string} */
    private function grabEvents(string $deliveryId): array
    {
        $delivery = $this->grab()->getDelivery($deliveryId);

        if (! is_array($delivery)) {
            return [[], 'unavailable', null];
        }

        $events = [];

        // Grab reports a state plus a timeline of stamped milestones. The
        // timeline gives dated rows; the status covers the case where Grab has
        // moved on but stamped nothing yet (ALLOCATING, for instance).
        $milestones = [
            'createdAt'   => 'Booking created',
            'allocatedAt' => 'Rider assigned',
            'pickup'      => 'Rider collecting the parcel',
            'pickedUpAt'  => 'Picked up by the rider',
            'dropoff'     => 'Out for delivery',
            'completedAt' => 'Delivered',
            'cancelledAt' => 'Delivery cancelled',
        ];

        foreach ($milestones as $key => $text) {
            $at = $delivery['timeline'][$key] ?? null;
            if (! empty($at)) {
                $events[] = $this->event($at, $text, '');
            }
        }

        $status = trim((string) ($delivery['status'] ?? ''));
        if ($status !== '' && $events === []) {
            $events[] = $this->event(null, $this->humanize($status), '');
        }

        return [$events, $events === [] ? 'unavailable' : 'courier', null];
    }

    /**
     * A plausible timeline for a MOCK- waybill, derived from when the shipment
     * was booked. No courier is contacted — there is nothing to contact about.
     *
     * @return list<array<string, mixed>>
     */
    private function mockEvents(string $courier, array $row, array $context): array
    {
        $bookedAt = strtotime((string) ($row['created_at'] ?? 'now')) ?: time();
        $where    = trim((string) ($context['destination'] ?? '')) ?: 'Destination city';

        $events = [];

        foreach (self::MOCK_STEPS as [$hours, $stage, $text, $place]) {
            $at = $bookedAt + ($hours * HOUR);
            if ($at > time()) {
                break;
            }

            $events[] = [
                'at'          => date('Y-m-d H:i:s', $at),
                'description' => strtr($text, [':courier' => self::courierName($courier)]),
                'location'    => strtr($place, [':destination' => $where]),
                'stage'       => $stage,
            ];
        }

        return $events;
    }

    // ------------------------------------------------------------------
    // Courier clients — each capped at couriers.trackTimeout, since a
    // shopper is waiting. Protected so a test can answer in their place.
    // ------------------------------------------------------------------

    protected function jne(): JneClient
    {
        $client = new JneClient($this->config);
        $client->setTimeout((float) $this->config->trackTimeout);

        return $client;
    }

    protected function ninja(): NinjaClient
    {
        $client = new NinjaClient($this->config);
        $client->setTimeout((float) $this->config->trackTimeout);

        return $client;
    }

    protected function ljr(): LjrClient
    {
        $client = new LjrClient($this->config);
        $client->setTimeout((float) $this->config->trackTimeout);

        return $client;
    }

    protected function grab(): GrabClient
    {
        $client = new GrabClient($this->config);
        $client->setTimeout((float) $this->config->trackTimeout);

        return $client;
    }

    // ------------------------------------------------------------------
    // Normalization helpers
    // ------------------------------------------------------------------

    /**
     * One normalized scan. The stage is inferred from the text, because no
     * courier here exposes a machine-readable status we could map directly.
     *
     * @return array{at:?string, description:string, location:string, stage:string}
     */
    private function event(mixed $at, string $description, string $location): array
    {
        return [
            'at'          => $this->parseDate($at),
            'description' => $this->humanize($description),
            'location'    => trim($location),
            'stage'       => $this->stageFor($description),
        ];
    }

    /**
     * Which stage a scan line belongs to. Falls back to in-transit rather than
     * to "unknown": a courier that scanned the parcel at all has it moving,
     * and an unrecognized phrase is far more likely to be a hub scan than
     * anything else.
     */
    public function stageFor(string $text): string
    {
        $haystack = strtolower($text);

        foreach (self::STAGE_KEYWORDS as $stage => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $stage;
                }
            }
        }

        return self::STAGE_IN_TRANSIT;
    }

    /**
     * The state to headline. Delivery wins wherever it appears in the list:
     * couriers routinely append an administrative scan after the POD, and
     * reading only the newest row would then un-deliver a delivered parcel.
     *
     * @param list<array<string, mixed>> $events
     */
    public function overallStage(array $events): string
    {
        if ($events === []) {
            return self::STAGE_BOOKED;
        }

        foreach ($events as $event) {
            if ($event['stage'] === self::STAGE_DELIVERED) {
                return self::STAGE_DELIVERED;
            }
        }

        return (string) $events[0]['stage'];
    }

    /**
     * Courier timestamps arrive in whatever the courier felt like sending.
     * Returns 'Y-m-d H:i:s', or null when the value cannot be trusted — an
     * undated scan still shows, it just shows without a time.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        // Explicit formats first. strtotime reads "09-06-2020" as d-m-Y, which
        // is right for JNE, but leaving every courier to its guesswork is how
        // a day and a month quietly swap places.
        //
        // Each format starts with "!", which resets every field the value does
        // not carry. Without it createFromFormat() fills them from the current
        // moment, and a scan dated "09-06-2020" came out stamped with whatever
        // time the page happened to be loaded.
        foreach (['!d-m-Y H:i:s', '!d-m-Y H:i', '!d-m-Y', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP', '!Y-m-d'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Courier scans are shouted in upper case and, for Grab, in SCREAMING_SNAKE.
     * Neither reads as a sentence to a shopper.
     */
    private function humanize(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $text)) ?? $text);

        if ($text === '') {
            return $text;
        }

        // Only reshape text that is entirely upper case; a courier that
        // already writes in mixed case is left exactly as it wrote it.
        if ($text === mb_strtoupper($text)) {
            $text = mb_convert_case(mb_strtolower($text), MB_CASE_TITLE, 'UTF-8');
        }

        return $text;
    }

    /** The place JNE puts in [SQUARE BRACKETS] at the end of a scan line. */
    private function bracketed(string $text): string
    {
        return preg_match('/\[([^\]]+)\]\s*$/', $text, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * Newest first. Undated scans keep the courier's own order and sit at the
     * bottom, where they cannot masquerade as the latest news.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function sortNewestFirst(array $events): array
    {
        $dated   = [];
        $undated = [];

        foreach ($events as $index => $event) {
            if ($event['at'] === null) {
                $undated[] = $event;
            } else {
                $dated[] = [$event['at'], $index, $event];
            }
        }

        // The original index breaks ties, so scans stamped to the same minute
        // keep a stable order instead of reshuffling on every page load.
        usort($dated, static fn (array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_merge(array_column($dated, 2), $undated);
    }

    /** Where the shopper can see this shipment on the courier's own site. */
    private function trackingUrl(string $courier, array $row): string
    {
        $waybill = rawurlencode((string) ($row['waybill'] ?? ''));

        return match ($courier) {
            AirwaybillModel::COURIER_NINJA => 'https://www.ninjaxpress.co/en-id/tracking?id=' . $waybill,
            AirwaybillModel::COURIER_GRAB  => ((string) ($row['tracking_url'] ?? '')) ?: 'https://www.grab.com/id/express/',
            // SPX publishes no deep-link parameter, so this is their tracker
            // with the number shown beside it for the shopper to paste.
            AirwaybillModel::COURIER_SPX   => 'https://spx.co.id/en/track',
            AirwaybillModel::COURIER_LJR   => 'https://ljrlogistics.com/tracking-ekspedisi-pengiriman-barang/',
            default                        => 'https://www.jne.co.id/en/tracking/trace',
        };
    }
}

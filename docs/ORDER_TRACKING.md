# Order tracking — implementation handoff

How customer order tracking works in this app, why it is shaped the way it is,
and exactly which legacy CI3 code each part came from.

Legacy source referenced throughout:
`/Users/muhamad/Documents/super-coding/executive_reg_shipping` (CI3).
Paths without a prefix are in this repo (CI4).

---

## 1. The headline: this is not a port

The legacy app **had tracking, but had no tracking page**. Its tracking loop
existed for one purpose — to notice a parcel had moved and then mark the
Shopify order fulfilled. There is no view in `application/views/front/` where a
shopper could ever ask "where is my parcel". Confirm for yourself:

```bash
ls /Users/muhamad/Documents/super-coding/executive_reg_shipping/application/views/front/
# addstore.php  faq.php  help.php  index.php  list_ongkir.php  privacy.php
```

So this feature is **half port, half new build**:

| Part | Origin |
|---|---|
| Courier tracing calls (JNE / Ninja / LJR) | Ported from CI3, same endpoints |
| Auto-fulfil-on-delivery | **Deliberately not ported** — see §6 |
| Customer-facing page and JSON API | **New** — no legacy equivalent |
| Normalized event timeline and stages | **New** |
| Mock timeline | **New** |

Read §6 before changing anything: the single biggest difference from CI3 is
that tracking here never writes.

---

## 2. Legacy map — what CI3 did

### 2.1 The cron entry point

`application/controllers/Jne.php:1213` — `track_awb()`

```php
$orders = $this->db->query(
  "SELECT * FROM jne_airwaybill
   WHERE jne_status = 0 AND jne_date >= DATE(NOW() - INTERVAL 3 DAY)"
)->result();
```

For each row it branched on `jne_courier`, polled that courier, and on a hit
called Shopify `POST /fulfillments.json` and set `jne_status = 1`.

Courier codes (assigned at `Jne.php:317-335`):

| `jne_courier` | Courier | Handled in `track_awb()`? |
|---|---|---|
| `1` | JNE | yes (the `else` branch) |
| `2` | LJR | yes |
| `3` | Ninja | yes |
| `4` | SPX | **no — falls into the JNE branch** |

That last row is a real legacy bug: an SPX waybill was traced against JNE's
API, which can never return anything for it, so SPX shipments were never
auto-fulfilled by the cron. `fullfiled_order()` (`Jne.php:985`, the manual
path) *does* handle code `4` at `Jne.php:1041`, which is how SPX orders
actually got fulfilled.

There is also `track_awb_old()` at `Jne.php:235` — a JNE-only earlier version
on a 7-day window.

**Scheduling:** despite the name, no schedule is committed anywhere in the CI3
repo. `track_awb()` opens with `Access-Control-Allow-*` headers, so it was
invoked by hitting its URL — an external cron or a manual call.

### 2.2 The trigger conditions (all three are odd)

| Courier | Condition, verbatim | What it actually means |
|---|---|---|
| Ninja `Jne.php:1229` | `if ($ninjatrack['status'] != 'Delivered') continue;` | Fulfil only on delivery. Reasonable. |
| LJR `Jne.php:1276` | `if ($ljrtrack[3]['statusName'] != 'Delivered') continue;` | **Hardcoded index `3`.** Reads the 4th status row whatever it is; a shipment with fewer rows throws. |
| JNE `Jne.php:1322` | `if (isset($track['history'][0]['code']) == false) continue;` | Fulfils on the **first scan of any kind**, not on delivery. |
| JNE (old) `Jne.php:248` | `if ($track['cnote']['pod_status'] == 'ON PROCESS')` | Fulfils while explicitly *in progress*. |

None of these carried over as fulfilment triggers, because nothing in CI4
auto-fulfils. They carried over only as *sources of scan data*.

### 2.3 The courier calls — ported as-is

| CI3 | Endpoint | CI4 equivalent |
|---|---|---|
| `Shopify.php:332` `api_trace_jne($url)` | `POST http://apiv2.jne.co.id:10101/tracing/api/list/v1/cnote/{awb}` with `username` + `api_key` form fields | `app/Libraries/Couriers/JneClient.php:75` `trace()` |
| `Shopify.php:639` `get_track_ljr($awb)` | `GET https://lestarijayaraya.co.id:10433/api/v2/dlm_api/_table/shipment_status?filter=airwaybillNumber={awb}&api_key=…` | `app/Libraries/Couriers/LjrClient.php:34` `track()` |
| `Shopify.php:733` `get_status_track($awb)` | `GET {proxy}/get_status_track/{awb}` — one status word | `WidgetProxyClient.php:68` `trackStatus()` → `NinjaClient.php:127` `track()` |
| `Shopify.php:756` `get_all_track_ninja($awb)` | `GET {proxy}/trackorder/{awb}` — full scan list | `WidgetProxyClient.php:80` `trackHistory()` → `NinjaClient.php:135` `history()` |

`get_all_track_ninja` was **defined but never called** in CI3. It is the more
useful of the two, and it is what the CI4 page leads with — `trackStatus` is
kept only as the fallback when the proxy returns no scan list.

Credentials moved from hardcoded strings in `Shopify.php` to `.env`
(`couriers.jneUsername`, `couriers.jneApiKey`, `couriers.ljrBaseUrl`,
`couriers.ljrApiKey`, `couriers.proxyBaseUrl`).

### 2.4 Schema

| CI3 `jne_airwaybill` | CI4 `airwaybills` |
|---|---|
| `jne_order_id` | `order_id` |
| `jne_waybill` | `waybill` |
| `jne_courier` (int 1–4) | `courier` (string `jne`/`ljr`/`ninja`/`spx`/`grab`) |
| `jne_status` 0/1 | `status` — `AirwaybillModel::STATUS_PENDING` / `STATUS_FULFILLED` |
| `jne_date` | `created_at` |
| — | `tracking_url` (new; Grab returns a per-delivery URL) |

**No migration was needed for tracking.** The feature reads existing columns
and stores nothing of its own — see §5.

---

## 3. What was built (file map)

```
app/Libraries/Tracking/
  TrackingService.php     poll a courier → one normalized timeline
  ShipmentLookup.php      reference + email → one airwaybill row

app/Controllers/
  Track.php                     GET|POST /track          (hosted page)
  Api/StorefrontTracking.php    POST /api/storefront/track (JSON)

app/Views/
  layouts/public.php      public shell (no admin chrome)
  track/index.php         form + progress rail + timeline

app/Libraries/Couriers/
  LjrClient.php           NEW — LJR status table
  GrabClient.php:181      NEW method — getDelivery()
  NinjaClient.php:135     NEW method — history()
  WidgetProxyClient.php:80 NEW method — trackHistory()

app/Config/Routes.php:14,15,42,50   routes
tests/unit/TrackingServiceTest.php  normalization
tests/unit/TrackPageTest.php        the access rule
```

Both front ends call the same two libraries, in this order:

```
reference + email
      │
      ▼
ShipmentLookup::find()          ← the access rule lives here, once
      │  returns { awb row, summary } or null
      ▼
TrackingService::track($awb)    ← the courier calls live here, once
      │  returns { stage, events[], trackingUrl, source, … }
      ▼
HTML view  or  JSON response
```

Keeping the rule in one class is the point: a change to who may see what
cannot land on the page but miss the API.

---

## 4. The access rule (read before touching `ShipmentLookup`)

**A lookup needs the order number _or_ waybill, together with the email
address on the order.**

Order numbers are short and sequential (`#1116`, `#1117`, …) and mock waybills
are worse (`MOCK-JNE-951116`). A reference-only lookup is therefore a scraper's
shopping list: recipient name, destination city and delivery time for every
order the warehouse ships, obtainable by counting upwards. The email pairing is
what makes the endpoint safe to expose — it is the same bargain Shopify's own
order-status page strikes.

Three properties hold it up. Do not weaken any of them casually:

1. **One answer for two failures.** A wrong email and a nonexistent reference
   return byte-identical output. Otherwise the form becomes an oracle: "does
   order #1200 exist?" is answerable by watching which error comes back.
   Enforced by `tests/unit/TrackPageTest.php::testAWrongEmailIsIndistinguishableFromAnUnknownOrder`,
   which diffs the two whole response bodies.
2. **`hash_equals`, not `===`** (`ShipmentLookup.php:117`) so the comparison
   does not leak how much of an address was right through its timing.
3. **Store scoping** (`ShipmentLookup.php:63`). A storefront key belongs to one
   store; a lookup that resolves to another store's order returns null. Rows
   predating `store_id` fall through to the Shopify check, which is itself
   scoped to the caller's store.

Throttling is 15 lookups/min per IP (page) and per store per IP (API) — set for
the *courier's* benefit, since a hit can trigger an upstream call.

### Fallback when the local order row is missing

`orders` rows are written by the `orders/create` webhook. If webhooks were not
registered when an order came in, there is no local email to check against, so
`ShipmentLookup::liveOrder()` (`:137`) fetches the order from Shopify instead.
Any failure there returns null — **an unreachable store must read as "not
found", never as a match.**

---

## 5. TrackingService — how a courier reply becomes a timeline

`app/Libraries/Tracking/TrackingService.php`

### 5.1 Contract

Never throws. A shopper is waiting on the page, so a courier that is down
degrades to "no scans yet". Returns:

```php
[
  'courier' => 'jne', 'courierName' => 'JNE',
  'waybill' => '…', 'trackingUrl' => '…',
  'stage'   => 'out_for_delivery', 'stageLabel' => 'Out for delivery',
  'events'  => [ ['at' => 'Y-m-d H:i:s'|null, 'description' => '…',
                  'location' => '…', 'stage' => '…'], … ],  // newest first
  'source'  => 'courier' | 'mock' | 'unavailable',
  'note'    => null,          // set when a courier has no tracking API
  'checkedAt' => 'Y-m-d H:i:s',
]
```

### 5.2 Per-courier collection (`:182` `collect()`)

| Courier | Method | Notes |
|---|---|---|
| JNE | `:220` `jneEvents()` | reads `history[]`; falls back to `cnote.pod_status` when history is empty. Location parsed from JNE's trailing `[BRACKETS]` (`:516`). |
| Ninja | `:261` `ninjaEvents()` | tries `history()` first, then the single `track()` status word. The proxy has wrapped this payload differently over time, so several shapes are accepted. |
| LJR | `:315` `ljrEvents()` | accepts both the bare list CI3 saw and DreamFactory's `{"resource": […]}`. **No hardcoded index** — contrast CI3's `$ljrtrack[3]`. |
| Grab | `:335` `grabEvents()` | `GET /deliveries/{id}`; the waybill *is* the Grab delivery id. Milestones from `timeline`, else the bare status. |
| SPX | `:182` inline | SPX publishes no tracking API. Returns no events plus a `note`, and links to their tracker. Nothing is invented. |

### 5.3 Stage inference (`:80` `STAGE_KEYWORDS`, `:429` `stageFor()`)

No courier here exposes a machine-readable status, so the stage is inferred
from the scan text by substring match. **Order of the keyword table is the
whole design** — several real scans match more than one group:

- `RECEIVED AT SORTING CENTER` contains a pickup word *and* a transit word.
  Transit is checked first, so it reads as transit.
- `SHIPMENT RECEIVED BY JNE COUNTER OFFICER` — a pickup scan. An early draft
  had `received by` in the *delivered* list, which showed a parcel as delivered
  the hour it was collected. The delivered list is now deliberately narrow
  (`delivered`, `terkirim`, `completed`, `selesai`, `telah diterima`,
  `diterima oleh`) — see the comment at `:82`.
- Unrecognized wording falls back to **in transit**, not "unknown": a courier
  that scanned the parcel at all has it moving.

`overallStage()` (`:451`) — **delivered wins wherever it appears in the list**,
not just at the top. Couriers routinely append an administrative scan after the
POD, and reading only the newest row would un-deliver a delivered parcel.

### 5.4 Nothing is persisted

The service is read-through with a 5-minute cache per waybill (`:71`
`CACHE_TTL`). Failures are **not** cached (`:212`) — caching an outage would
keep showing "no scans" for five minutes after the courier recovered.

This is why the feature needed no migration. If you later want tracking state
in the admin list or in webhooks, that is when you add columns — not before.

---

## 6. Why tracking never fulfils

CI3's loop ended in `POST /fulfillments.json` + `jne_status = 1`. CI4's does
not, on purpose:

- This app's rule is **manual fulfilment only** — an operator presses Generate
  AWB, which calls `AwbService::fulfill()` (`app/Libraries/Awb/AwbService.php:571`).
  That is the *only* path that writes a fulfilment.
- With mock mode on (the default), auto-fulfilment would email real
  customers about parcels that do not exist.
- CI3's JNE trigger fulfilled on the first scan of any kind, so "auto-fulfil on
  delivery" was not even what it did.

The legacy loop still exists, ported and **switched off**:
`app/Commands/AwbTrack.php` (`php spark awb:track`) → `AwbService::track()`
(`:637`). It is read-only — it logs which shipments are moving and creates
nothing — and refuses to run while `ACTIVE = false` (`AwbTrack.php:24`),
because with mock mode on there are no real waybills to poll.

**If you ever re-enable it, keep it read-only.** Making it fulfil again
reintroduces the CI3 behaviour this app deliberately dropped.

---

## 7. Mock mode

Mock mode (Admin → Settings) is on by **default**, and it invents waybills locally
as `MOCK-{courier}-{numberId}` (`AwbService.php:300`). Without special handling
the tracking page would be a permanently empty box on every environment that
has not gone live.

So `TrackingService::mockEvents()` (`:379`) synthesizes a timeline from the
row's `created_at` using `MOCK_STEPS` (`:116`): booked at +0h, picked up +3h,
transit +10h/+22h, out for delivery +32h, delivered +44h. Only steps whose hour
has passed appear, so a mock parcel visibly moves over its first two days.

Detection is on the `MOCK-` prefix, **not** on the config flag — a real waybill
booked before the flag was flipped must still be polled for real.

Both front ends surface `source: "mock"` and label it a demo shipment. Keep
that: a demo store must not present a fictional parcel as real.

---

## 8. Extending it

### Add a courier

1. Add a client method returning the raw payload
   (`app/Libraries/Couriers/YourClient.php`).
2. Add a `yourEvents()` normalizer in `TrackingService`, returning
   `[events, source, note]`.
3. Wire it into the `match` in `collect()` (`:182`).
4. Add its tracking URL to `trackingUrl()` (`:550`) and its display name to
   `COURIER_NAMES` (`:57`).
5. Add its real scan wording to the `stageFor()` test — see below.

### Change stage wording

Edit `STAGE_KEYWORDS` (`:80`) and **add the real courier phrase to
`TrackingServiceTest::testReadsRealCourierWordingOntoTheRightStage`** in the
same commit. That test exists because this is the part that looks trivial and
is not; it already caught one pickup-reads-as-delivered bug.

### Add a field to the JSON response

`StorefrontTracking::lookup()` projects explicitly rather than dumping the
service output, so a storefront cannot come to depend on internals. Add the
field to the projection deliberately.

---

## 9. Testing

```bash
vendor/bin/phpunit tests/unit/TrackingServiceTest.php   # normalization, stages, mock
vendor/bin/phpunit tests/unit/TrackPageTest.php         # the access rule
vendor/bin/phpunit                                      # everything (96 tests)
```

Manual, against dev data:

```bash
php spark serve --port 8080
# → http://localhost:8080/track
#   #1116 + natasha.cindy@sirclo.com   → JNE, full timeline
#   #1117 + rrr@gmail.com              → SPX, links out (no API)
#   #1116 + wrong@example.com          → same message as #9999
```

```bash
curl -s -X POST http://localhost:8080/api/storefront/track \
  -H 'Content-Type: application/json' \
  -H 'X-Storefront-Key: pk_…' \
  -d '{"reference":"#1116","email":"natasha.cindy@sirclo.com"}' | jq
```

Storefront panel: `cd mock-storefront && npm run dev` (port **5173** — the only
origin in `cors.storefrontOrigins`).

### Two traps worth knowing

**`Config\View::$saveData = true`.** View data persists between renders in one
process. `Track::page()` (`Track.php:99`) therefore passes *every* key on every
render. A view that leaned on "this variable is simply not set" would show one
shopper's shipment to the next. This bit during test writing and is real.

**Feature tests need the CSRF token.** `POST /track` is CSRF-protected (only
`api/*` is exempt). See the `lookup()` helper in `TrackPageTest`.

---

## 10. Going live

- [ ] Switch to live in Admin → Settings — until then every timeline is simulated.
- [ ] `couriers.jneUsername` / `jneApiKey` / `jneTraceUrl` set (JNE tracing).
- [ ] `couriers.proxyBaseUrl` set (Ninja).
- [ ] `couriers.ljrBaseUrl` / `ljrApiKey` set, or LJR silently returns no scans.
- [ ] `cors.storefrontOrigins` includes the real storefront origin, or the
      browser blocks the JSON call.
- [ ] Decide whether `/track` is linked from the shipping-confirmation email.

**Known gaps, stated plainly:**

- SPX has no tracking API. It will keep linking out until Shopee publishes one.
- Grab tracing depends on the delivery id being stored as the waybill; a Grab
  shipment booked outside `AwbService::generateGrab()` will not trace.
- The `MOCK-` timeline is fiction by design. Do not build alerting on it.
- `awb:track` is inactive (§6). Nothing polls couriers in the background — a
  courier is only called when someone opens the page.

---

## 11. Code appendix — the parts that carry the design

Excerpts, current as of this commit. The files are the source of record; these
are here so a reviewer can see the shape without opening five files.

### 11.1 Courier dispatch, cache, and mock detection

`TrackingService.php:182` — the whole per-courier fan-out is one `match`.

```php
    private function collect(string $courier, string $waybill, array $row, array $context): array
    {
        if ($waybill === '') {
            return [[], 'unavailable', 'This order has no waybill yet.'];
        }

        // Keyed on the waybill rather than the courier row so that a mock and
        // a real shipment can never share an entry.
        if (str_starts_with($waybill, 'MOCK-')) {
            return [$this->mockEvents($courier, $row, $context), 'mock', null];
        }

        $cacheKey = 'track_' . md5($courier . '|' . $waybill);
        $cache    = service('cache');
        $cached   = $cache->get($cacheKey);

        if (is_array($cached)) {
            return [$cached['events'], $cached['source'], $cached['note']];
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
            $cache->save($cacheKey, ['events' => $result[0], 'source' => $result[1], 'note' => $result[2]], self::CACHE_TTL);
        }

        return $result;
    }
```

Three decisions live in those lines:

- **Mock is detected by the `MOCK-` prefix, not the config flag** — a real
  waybill booked before mock mode was switched must still be polled for real.
- **The cache key is the waybill**, so a mock and a real shipment can never
  collide.
- **Only a non-empty answer is cached.** Caching an outage would keep showing
  "no scans" for five minutes after the courier came back.

### 11.2 A courier normalizer — the contract every one follows

Return `[events, source, note]`. Never throw. `JneClient::trace()` is the CI3
`api_trace_jne()` call, unchanged in substance.

```php
    private function jneEvents(string $waybill): array
    {
        try {
            $trace = (new JneClient($this->config))->trace($waybill);
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
```

To add a courier, copy that shape: swallow transport failures into
`[[], 'unavailable', null]`, build events through `$this->event()` so the stage
is inferred consistently, and return `'courier'` only when you actually have
scans.

### 11.3 `event()` — where a raw scan becomes a typed one

```php
    private function event(mixed $at, string $description, string $location): array
    {
        return [
            'at'          => $this->parseDate($at),
            'description' => $this->humanize($description),
            'location'    => trim($location),
            'stage'       => $this->stageFor($description),
        ];
    }
```

### 11.4 Stage inference, and the ordering that matters

```php
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
```

The keyword table it walks (`TrackingService.php:80`) is checked **delivered →
exception → out-for-delivery → in-transit → picked-up**. That order is the
design: JNE's `RECEIVED AT SORTING CENTER` matches both a pickup word and a
transit word, and only this sequence reads it as transit.

### 11.5 Delivered wins wherever it appears

```php
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
```

### 11.6 The access rule, in full

`ShipmentLookup.php:28`. Read this method before changing anything about who
can see what.

```php
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
```

And the email comparison it ends on:

```php
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
```

### 11.7 Route wiring

`app/Config/Routes.php`. The page is CSRF-protected; the API is exempt via the
existing `api/*` rule in `Config\Filters::$globals` and picks up
`cors:storefront` from the `api/storefront/*` URI filter — so no filter changes
were needed.

```php
$routes->get('track', 'Track::index');
$routes->post('track', 'Track::lookup');

$routes->post('api/storefront/track', 'Api\StorefrontTracking::lookup');
$routes->options('api/storefront/track', static fn () => service('response')->setStatusCode(204));
```

The `OPTIONS` route is not optional: routing runs before URI-pattern filters, so
without a matching route the browser's preflight 404s and the CORS filter never
gets to answer it.

### 11.8 New courier client — LJR, the smallest complete example

`app/Libraries/Couriers/LjrClient.php` is the shortest full client in the
codebase and the best template for a new one.

```php
    public function track(string $awb): array
    {
        if ($this->config->ljrBaseUrl === '' || $this->config->ljrApiKey === '') {
            return [];
        }

        $client = single_service('curlrequest', ['timeout' => 15]);

        try {
            $response = $client->get(rtrim($this->config->ljrBaseUrl, '/') . '/_table/shipment_status', [
                'query' => [
                    'filter'  => 'airwaybillNumber=' . $awb,
                    'api_key' => $this->config->ljrApiKey,
                ],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
                // LJR serves the API on :10433 behind a chain PHP does not carry.
                'verify'      => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('error', 'LJR tracking failed for {awb} (HTTP {code})', [
                    'awb'  => $awb,
                    'code' => $response->getStatusCode(),
                ]);

                return [];
            }

            $decoded = json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'LJR tracking error for {awb}: {msg}', ['awb' => $awb, 'msg' => $e->getMessage()]);

            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rows = $decoded['resource'] ?? $decoded;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
```

Note the two defences the CI3 version lacked: an empty-config guard, so an
unconfigured LJR returns no scans instead of erroring; and accepting both the
bare list CI3 indexed into and DreamFactory's `{"resource": [...]}` wrapper,
instead of CI3's `$ljrtrack[3]['statusName']`.

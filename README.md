# Delami Shipping Rates API

Shipping for Delami's Shopify stores: multi-courier rates at checkout and in
headless carts (JNE, Ninja Xpress, SPX, GrabExpress instant, Click and
Collect), airway-bill booking and label printing, and customer order
tracking. Built on CodeIgniter 4, with an admin backend secured by Shield.

## Stack

- PHP 8.4, CodeIgniter 4.7
- SQLite by default (`writable/database/delami.sqlite`); MySQL with
  `DB_CONNECTION=mysql`
- Shield session auth (`admin` group), public registration off
- Shopify Admin **GraphQL** API (2026-07), OAuth 2.0 authorization code grant
- Courier rates through the Delami widget proxy (parallel + cached);
  GrabExpress through Grab's Express API; Google Geocoding for drop-off points
- Local barcode (Code128 SVG) and QR generation — no external barcode services
- Every secret lives in `.env`; `.env.example` mirrors it line for line

## Setup

```bash
composer install
cp .env.example .env            # then fill in the blank secrets
php spark migrate --all
ADMIN_EMAIL=you@delamibrands.com ADMIN_PASSWORD='...' php spark db:seed AdminUserSeeder
php spark serve
```

### Fresh database

```bash
php spark db:fresh-sqlite            # schema + WAL + an admin login
php spark db:fresh-sqlite --stores   # ...plus the four brand demo stores
```

Creates `writable/database/delami.sqlite`, runs every migration against it,
enables WAL, and seeds an admin user — printing a generated password unless
`ADMIN_EMAIL` / `ADMIN_PASSWORD` are set. It refuses to replace an existing
file without `--force`, and asks first in production.

### Connect a Shopify store

1. Create an app in the Shopify Dev Dashboard with the redirect URL
   `{baseURL}/shopify/oauth/callback`.
2. In **Admin → Stores**, enter the shop domain and the app's Client ID and
   Client Secret, then **Authorize with Shopify**. The secret and the offline
   access token are stored **encrypted** in the `stores` table, and the
   webhooks are registered automatically.
3. In **Edit settings** for that store:
   - **Register / re-point carrier service** (needs `write_shipping`).
     Shopify freezes the callback URL at registration, so press it again
     whenever the URL changes — a stale one shows no error, rates just stop
     appearing at checkout.
   - **Generate key** issues the publishable storefront key that headless
     carts send as `X-Storefront-Key`. Browser callers also need their origin
     in `cors.storefrontOrigins` in `.env`.
   - **Generate server key** issues the secret a storefront's own server sends
     as `X-Storefront-Secret`, for a per-store rate limit instead of a per-IP
     one. Shown once; only its hash is stored.
   - **Webhooks on this site** — turn the store's order webhooks on or off for
     this deployment, or re-register them.

### Mock AWB mode

Mock mode is switched in **Admin → Settings** (stored in the `settings` table,
not `.env`) and is **on** until someone turns it off. While on, generating an
AWB invents a `MOCK-…` waybill locally and makes **no courier API call**. The
Shopify order is still fulfilled with that mock tracking number, so the whole
pipeline is testable end to end — but the customer is **not** notified. Labels
still render. Switching to live needs a ticked confirmation, and the page shows
who last changed it and when. Read it in code with
`App\Libraries\Awb\MockMode::enabled()`.

An old `couriers.mockAwb` line left in a server's `.env` still applies until the
first choice is saved in the admin; after that the admin setting wins.

### Cron

Nothing scheduled. `awb:track` is inactive while mock mode is on — the only
waybills are locally invented `MOCK-` numbers no courier can report on.
Re-enable it in `app/Commands/AwbTrack.php` once real shipments are booked:

```cron
*/15 * * * *  cd /path/to/app && php spark awb:track       # read-only tracking report
```

### Tests

```bash
vendor/bin/phpunit
```

## Order → AWB flow

1. The shopper picks a rate in the headless cart or at checkout; Shopify
   records it on the order as `shipping_line.title` / `.code`.
2. Shopify sends the order to this site through the `orders/create` and
   `orders/updated` webhooks, and it is stored in the `orders` table. That is
   the whole of `/admin/orders`: one list per store, of the orders this site
   has received — nothing on the page queries Shopify. A store's order
   webhooks can be switched off per site (Admin → Stores → Edit settings),
   which is how a store connected to both a dev tunnel and production keeps
   its orders on only one of them. An update older than the stored copy (by
   Shopify's `updated_at`) is dropped, since deliveries can arrive out of
   order.
3. Generate AWB (`POST /admin/awb/generate/{orderId}`, paid and uncancelled
   orders only) reads the live order from Shopify, books the shipment with the
   courier that the chosen `service_code` maps to
   (`Config\Couriers::$serviceMap`), stores the waybill, and fulfills the
   Shopify order with tracking.
   **Fulfillment is always manual — nothing else ever fulfills an order.**
4. Print the label with `GET /admin/awb/print/{orderId}` (read-only; never
   re-books). The Orders list does not show buttons for either — both routes
   are kept and work when called directly.

Orders placed before this site started receiving a store's order webhooks are
not listed. Generate AWB and Print label read the live order, so they only work
within Shopify's 60-day Order API window.

## Endpoints

| Route | Purpose |
|---|---|
| `POST /carrier/rates/{store}?token=…` | Shopify CarrierService rate callback (subunit prices) |
| `POST /api/storefront/rates` | Headless cart rates (publishable key header) |
| `POST /api/storefront/geocode` | Address ↔ coordinates for a cart's drop-off pin (same key) |
| `POST /api/storefront/track` | Order tracking JSON for a storefront (key + order number **and** email) |
| `GET \| POST /track` | Public customer tracking page (order number **and** email) |
| `GET /shopify/install?store={id}` | Start OAuth install (launched from Admin → Stores) |
| `GET /shopify/oauth/callback` | OAuth redirect (HMAC + state verified) |
| `POST /shopify/webhooks/{topic}` | `orders-create`, `orders-updated`, `app-uninstalled`, GDPR topics (HMAC verified) |
| `GET /admin` | Dashboard (Shield: admin group) |
| `GET /admin/orders` | Orders received by webhook, per store, with AWB state |
| `POST /admin/awb/generate/{orderId}` | Generate AWB (idempotent) + fulfill on Shopify |
| `GET /admin/awb/print/{orderId}` | Print the label (no side effects) |
| `GET /admin/rate-simulator` | Run the checkout rate engine by hand |
| `POST /admin/stores/carrier/{id}` | Register or re-point the store's carrier service (asks before taking it over from another site) |
| `POST /admin/stores/storefront-key/{id}` | Issue or rotate the store's publishable key |
| `POST /admin/stores/server-key/{id}` | Issue or rotate the store's secret server key |
| `POST /admin/stores/webhooks/{id}` | (Re-)register webhook topics for a store |
| `POST /admin/stores/order-webhooks/{id}` | Turn this site's order webhooks for a store on/off (only this site's subscriptions are touched; `app/uninstalled` stays on) |
| `GET /admin/settings`, `POST /admin/settings/courier-mode` | Mock / live courier mode |

## Headless storefront flow

`docs/HEADLESS_INTEGRATION.md` is the full guide; `delami-headless` is the
production implementation. In short:

1. The cart page quotes with `POST /api/storefront/rates` — same engine as
   checkout, so the cart price and the checkout price agree.
2. At handoff, stamp every cart line with `_delivery_method`
   (`standard` / `instant` / `collect`) and, for GrabExpress, `_delivery_lat` /
   `_delivery_lng` — Shopify forwards line properties to the carrier service,
   not cart attributes.
3. Add the delivery address, and for GrabExpress set the `coordinates` cart
   attribute (`"lat,lng"`), which Generate AWB books the rider from.
4. Query `cart.deliveryGroups(withCarrierRates: true)` in a `@defer` fragment,
   select the option with `cartSelectedDeliveryOptionsUpdate`, and redirect to
   `cart.checkoutUrl`.

## Customer order tracking

Once an AWB exists, the shopper can see the courier's scans two ways:

- **`/track`** — a hosted page in this app, no storefront work needed.
- **`POST /api/storefront/track`** — JSON, for a storefront that renders the
  timeline in its own design. Documented in `docs/HEADLESS_INTEGRATION.md` §3.3.

**`docs/ORDER_TRACKING.md`** is the implementation handoff: how it works and why
tracking never fulfils.

Both require the order number (or waybill) **together with the email on the
order**: references are short and sequential, so without the email pairing this
would be a way to read every shopper's destination. A wrong email is answered
identically to an unknown order, so neither can be used to probe the other.

Tracking is **read-only** — it reports what the courier says and never
fulfills. Fulfillment stays manual, via Generate AWB. Couriers are polled at
most once every 5 minutes per waybill; `MOCK-` waybills get a simulated
timeline instead, so the feature works with mock mode on (the default).
Courier coverage: JNE (trace), Ninja (widget proxy), LJR (status table), Grab
(delivery lookup). SPX publishes no tracking API, so it links out.

## Design rules

- `total_price` is returned in **subunits** (IDR ×100), per the CarrierService
  spec.
- Chargeable weight is `ceil(grams / 1000)`, minimum 1 kg — the same rule for
  quoting and for booking, so a parcel is never declared lighter than the
  shopper paid for.
- Each courier shows its own delivery estimate.
- AWB generation is **idempotent for every courier**: a waybill is never
  re-booked, a database claim stops two presses booking two parcels, and it is
  a POST — printing a label (a GET) never books or fulfills.
- Courier routing reads the rate's `service_code`, not a substring of the
  shipping title.
- Fulfillment happens **only** via Generate AWB; tracking is read-only.
- `appTimezone` is `Asia/Jakarta` — courier pickup windows are derived from the
  local hour.
- Courier lookups are parallel and cached, and a whole quote spends at most
  `couriers.rateBudgetSeconds` (4.5s) on upstream calls — every call is capped at
  what is left, parcel couriers first. A failed lookup is cached for only
  `couriers.failureCacheTtl` (60s), so an outage cannot blank a postcode.
- Shield passwords (bcrypt/argon2), public registration disabled, login
  throttled, CSRF on everything stateful. Admin CDN assets are pinned with
  Subresource Integrity.
- Shopify's privacy webhooks act: `customers/redact` erases that customer's
  details from their orders, `shop/redact` deletes the shop's orders and
  credentials.

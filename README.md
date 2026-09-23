# Delami Shipping — CI4

CodeIgniter 4.7 port of the legacy `executive_reg_shipping` (CI3) shipping features:
multi-courier shipping rates for Shopify (CarrierService) and AWB generation/printing
for JNE, Ninja Xpress, and SPX. Admin backend secured with CodeIgniter Shield.

## Stack

- PHP 8.4, CodeIgniter 4.7, MySQL (`delami_shipping_ci4`)
- Shield (session auth, `admin` group), all secrets in `.env`
- Shopify Admin **GraphQL** API (2025-07), OAuth 2.0 authorization code grant
- Rate lookups via the Delami widget proxy (parallelized + cached)
- Local barcode (Code128 SVG) + QR generation — no external barcode services

## Setup

```bash
composer install
cp .env.example .env            # then fill in the blank secrets
php spark migrate --all
php spark db:seed StoreSeeder
ADMIN_EMAIL=you@delamibrands.com ADMIN_PASSWORD='...' php spark db:seed AdminUserSeeder
php spark serve
```

### Shopify app (OAuth)

1. Create an app in the Shopify Dev Dashboard (or Partner Dashboard) with
   redirect URL: `{baseURL}/shopify/oauth/callback`.
2. Put its Client ID / Client Secret in `.env` (`shopify.apiKey`, `shopify.apiSecret`).
3. Visit `/shopify/install?shop={shop}.myshopify.com` and approve the scopes.
   The offline token is stored **encrypted** in the `stores` table; the
   `app/uninstalled` webhook is registered automatically.
4. In **Admin → Stores → Edit settings**, press **Register / re-point carrier
   service** (needs `write_shipping`). Shopify freezes the callback URL at
   registration, so press it again whenever the URL changes — a stale one
   shows no error, rates just stop appearing at checkout.
5. In the same modal, press **Generate key** to issue the publishable
   storefront key the headless cart pages send as `X-Storefront-Key`, and add
   their origins to `cors.storefrontOrigins` in `.env`.

### Fresh database

```bash
php spark db:fresh-sqlite            # schema + WAL + an admin login
php spark db:fresh-sqlite --stores   # ...plus the four legacy demo stores
```

Creates `writable/database/delami.sqlite`, runs every migration against it,
enables WAL, and seeds an admin user — printing a generated password unless
`ADMIN_EMAIL` / `ADMIN_PASSWORD` are set. It refuses to replace an existing
file without `--force`, and asks first in production.

### Cron

Nothing scheduled. `awb:track` is inactive while mock mode is on —
the only waybills are locally invented `MOCK-` numbers no courier can report
on. Re-enable it in `app/Commands/AwbTrack.php` once real shipments are booked:

```cron
*/15 * * * *  cd /path/to/app && php spark awb:track       # read-only tracking report
```

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

## Order → AWB flow

1. Shopper picks a rate on the headless cart page; Shopify records it on the
   order as `shipping_line.title` / `.code`. See `mock-storefront/` for a
   working example.
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
| `POST /api/storefront/track` | Order tracking JSON for a storefront (key + order number **and** email) |
| `GET \| POST /track` | Public customer tracking page (order number **and** email) |
| `GET /shopify/install?shop=…` | Start OAuth install |
| `GET /shopify/oauth/callback` | OAuth redirect (HMAC + state verified) |
| `POST /shopify/webhooks/{topic}` | `orders-create`, `orders-updated`, `app-uninstalled`, GDPR topics (HMAC verified) |
| `GET /admin` | Dashboard (Shield: admin group) |
| `GET /admin/orders` | Orders received by webhook, per store, with AWB state; Generate AWB on paid orders |
| `POST /admin/awb/generate/{orderId}` | Generate AWB (idempotent) + fulfill on Shopify |
| `GET /admin/awb/print/{orderId}` | Print the label (no side effects) |
| `POST /admin/stores/webhooks/{id}` | (Re-)register webhook topics for a store |
| `POST /admin/stores/order-webhooks/{id}` | Turn this site's order webhooks for a store on/off (only this site's subscriptions are touched; `app/uninstalled` stays on) |
| `GET /admin/settings`, `POST /admin/settings/courier-mode` | Mock / live courier mode |

## Headless storefront flow (cart-page rate chooser)

1. `cartDeliveryAddressesAdd` / `cartBuyerIdentityUpdate` — set address (collect zip).
2. Query `cart.deliveryGroups(withCarrierRates: true)` in a `@defer` fragment —
   Shopify calls this app's CarrierService callback and streams the options.
3. `cartSelectedDeliveryOptionsUpdate` with the chosen `deliveryOptionHandle`.
4. Redirect to `cart.checkoutUrl` — address and selection carry into checkout.

## Customer order tracking

Once an AWB exists, the shopper can see the courier's scans two ways:

- **`/track`** — a hosted page in this app, no storefront work needed.
- **`POST /api/storefront/track`** — JSON, for a storefront that renders the
  timeline in its own design. Documented in `docs/HEADLESS_INTEGRATION.md` §3.3;
  the mock storefront's "Track an order" panel is the reference implementation.

**`docs/ORDER_TRACKING.md`** is the implementation handoff: how it works, why
tracking never fulfils, and which legacy CI3 code each part came from.

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

## Differences vs the legacy CI3 app (intentional fixes)

- `total_price` returned in **subunits** (IDR ×100) per the CarrierService spec.
- Ninja rates show Ninja's own ETD (legacy reused JNE's).
- JNE weight tiers close the exact-boundary gaps (2.2 kg etc.).
- AWB generation is **idempotent for every courier** (legacy re-fired JNE/Ninja
  API calls on label reprint), and is a POST trigger rather than a GET that
  mutates on page load.
- Courier routing reads the rate's `service_code`, not a substring of the
  shipping title.
- Fulfillment happens **only** via the Generate AWB button; the tracking cron
  is read-only (legacy auto-fulfilled from `track_awb`).
- `appTimezone` is `Asia/Jakarta`, as the CI3 app was — courier pickup windows
  are derived from the local hour.
- Courier lookups are parallel + cached with short timeouts, so the callback
  stays inside Shopify's time budget.
- MD5 logins → Shield (bcrypt/argon2); public registration disabled.
- No unauthenticated privileged endpoints; CSRF on everything stateful.

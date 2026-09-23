# Delami mock headless storefront

A tiny Vite app standing in for the Hydrogen and Expo cart pages: add products
to a cart → enter an address → **see rates the CI4 app returned** → select one
→ continue to Shopify checkout.

The cart holds as many lines as you like and each line's quantity is editable,
because cart total and weight are exactly what the rate engine prices on —
that is how you test the courier thresholds (SPX min cart, JNE max cart,
insurance) without editing store settings. Changing the cart discards any rate
already quoted, since it was priced for a different basket.

Product cards carry a dropdown per variant option, and the price and weight
follow the selection — variants of one product can weigh different amounts,
and weight is what the engine bills per kg. Options with only one value (a
lone Color, or Shopify's "Default Title") get no dropdown; they still identify
the variant, so they are applied automatically.

```bash
npm install
cp .env.example .env.local   # shop domain + Storefront token + rate API + key
npm run dev
```

## Where the rates come from

The cart page calls the CI4 app **directly**:

```
POST /api/storefront/rates
X-Storefront-Key: pk_…
{"destination": {...}, "items": [{"grams": …, "price": …, "quantity": …}],
 "method": "standard"}
```

It does *not* go through Shopify. Shopify only invokes a CarrierService inside
checkout, and the Storefront API cannot reach one, so any headless cart that
wants to show shipping prices before checkout has to ask the app itself. That
is the whole reason the endpoint exists.

The key is **publishable**. A rate quote is public data — any shopper can get
one by filling a cart — so the key identifies and throttles a caller rather
than protecting a secret, which is what makes it safe to ship inside an Expo
build. Issue or rotate it in the CI4 admin: **Stores → Edit settings**.

`items[].price` is in **subunits**, exactly as Shopify's checkout sends it.
The endpoint refuses any other payload shape on purpose: quoting from whole
rupiah here while checkout quotes from subunits would show one price on the
cart and charge another at checkout.

## Track an order

The panel below the cart flow closes the loop: once the CI4 admin has generated
an AWB for an order, this is where the shopper sees the courier's scans.

```
POST /api/storefront/track
X-Storefront-Key: pk_…
{"reference": "#1001", "email": "shopper@example.com"}
```

The email is not a convenience — it is the access control. Order numbers are
short and sequential, so a reference-only lookup would hand every shopper's
destination to anyone counting upwards. A wrong email answers with exactly the
same `404` as an order that does not exist, so the form cannot be used to find
out which order numbers are real either.

With `couriers.mockAwb` on (the CI4 default) waybills are invented locally, so
the response comes back with `source: "mock"` and a simulated timeline — the
panel labels it a demo shipment rather than showing a fictional parcel as real.
To try it, generate an AWB in the CI4 admin and use that order's number and
email.

## One delivery method at a time

Standard and Instant are different products — parcel post arriving in days
versus a rider dispatched now — so picking one must rule out the other. The
cart page sends `"method"` with the quote, and the app returns only that
method's couriers.

Checkout needs telling separately, because Shopify calls the CarrierService
itself and that request knows nothing about which tab was open. The signal is a
**cart line property**, `_delivery_method`, stamped on every line just before
delivery options are queried: Shopify forwards `items[].properties` to a
carrier service verbatim, but does *not* forward cart-level attributes.

> Only rates from this app are governed by this. Static rates the store defines
> in Shopify itself (a flat "Standard", free shipping over X) appear at checkout
> regardless — remove them from the shipping profile if that matters.

## The checkout handoff, and the parity check it gives you

The cart quote carries no Shopify delivery-option handle, and only a handle
can be selected on a cart. So when a rate is chosen, the app:

1. stamps `_delivery_method` on every cart line,
2. attaches the address to the Shopify cart,
3. queries `deliveryGroups(withCarrierRates: true)` — Shopify now calls the
   CarrierService itself, restricted to the stamped method,
4. selects the option whose `code` matches the chosen `service_code`.

That round trip re-prices the cart through the *other* path, so it doubles as
a free parity check. Matching prices are logged; a mismatch raises an alert,
because it means the shopper was shown a price checkout would not honour.

Selecting the option stores it on the cart, so it carries into checkout and
becomes the order's `shipping_line` — which is what the admin routes the AWB
from.

## Two Shopify details worth knowing

**Carrier rates require `@defer`.** `deliveryGroups(withCarrierRates: true)`
is rejected outright without it:

```
DeliveryGroups(withCarrierRates: true) must be called with @defer
```

Shopify has to call the carrier service over the network, so it streams the
cart first and the rates second as `multipart/mixed`. `src/shopify.js` sends
`Accept: multipart/mixed` and merges the deferred chunk back onto the cart.
This only affects the handoff step now, not the cart page.

**Static rates appear alongside carrier rates.** The store's own "Standard"
rate shows up next to `BDD-*` options in the handoff response. Only the
`BDD-*` codes come from this app.

## Prerequisites

1. The CI4 app is reachable at `VITE_RATES_API_URL`.
2. **This page's origin is in `cors.storefrontOrigins`** in the CI4 `.env`
   (`http://localhost:5173` for `npm run dev`). Without it the browser blocks
   the call and you get an opaque "Failed to fetch".
3. A storefront key has been issued for the store.
4. For the checkout handoff only: the CarrierService is registered for the
   store, and the app is reachable at its public callback URL.
5. The cart total clears the courier thresholds — SPX is only offered from
   `couriers.spxMinCart` up, so a cheap test cart will show JNE only.

## Files

| File | Purpose |
|---|---|
| `src/rates.js` | Client for the CI4 cart-rate endpoint — what prices the cart |
| `src/tracking.js` | Client for the CI4 order-tracking endpoint |
| `src/shopify.js` | Storefront API client: products, cart, checkout handoff |
| `src/main.js` | Step-by-step cart UI, parity check, and the request log |
| `src/style.css` | Styles for the cart, rate chooser and log |
| `index.html` | Page shell |

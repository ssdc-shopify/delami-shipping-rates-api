# Delami Shipping — Headless & Mobile Integration Guide

How to show Delami shipping rates (JNE, Ninja, SPX **and GrabExpress instant**)
in a headless storefront (Hydrogen/React) or an Expo mobile app, and carry the
chosen rate into **native Shopify checkout**.

The reference implementation of everything below is in **`mock-storefront/`**
(`src/rates.js`, `src/shopify.js`, `src/main.js`). When in doubt, read that.

---

## 1. The mental model

**The app is a rate engine. Shopify and your storefront are both clients of it.**

The same pricing logic (`RateEngine::quote()`) is reachable through two doors:

```
                 ┌─ Shopify CarrierService callback  → native checkout (Shopify calls the app)
   RateEngine ───┤
                 └─ POST /api/storefront/rates        → your cart page   (you call the app)
```

- **Native checkout** already works with **no integration from you** — Shopify
  calls the app during checkout and shows the rates. You get this for free once
  the store's CarrierService is registered (Admin → Stores → Register).
- **Your cart page** can't reach a CarrierService (Shopify only calls it inside
  checkout), so to show shipping prices *before* checkout you call
  `POST /api/storefront/rates` yourself. Same engine → the cart price and the
  checkout price agree.

You only need this guide for the **cart page** and for **GrabExpress** (which
needs a map pin).

---

## 2. Prerequisites

| Thing | Where |
|---|---|
| **Publishable key** `pk_…` (per store) | Admin → Stores → Edit settings → Generate key |
| **CORS origin** (web only) | Add your domain to `cors.storefrontOrigins` in the server `.env` |
| **CarrierService registered** (for native checkout) | Admin → Stores → Register |

The publishable key is **not a secret** — it identifies and rate-limits a
caller, nothing more. It's safe in a browser bundle or a mobile app. Rotate it
in Admin if a build leaks somewhere it shouldn't.

**Base URL** in these docs: `https://YOUR-APP-DOMAIN` (e.g.
`https://delami-shipping.wesell.biz.id`).

---

## 3. API reference

### 3.1 `POST /api/storefront/rates`

Get shipping rates for a cart going to a destination.

**Headers**
```
Content-Type: application/json
X-Storefront-Key: pk_…
```

**Body**
```jsonc
{
  "destination": {
    "postal_code": "12950",        // drives JNE / Ninja / SPX
    "city": "jakarta selatan",
    "province": "JK",
    "country": "ID",
    // --- GrabExpress instant only (see §5) ---
    "latitude": "-6.2285501",
    "longitude": "106.8337856",
    "cityCode": "CGK",
    "address": "Plaza ORI, Jl. H.R. Rasuna Said 7, Kuningan Timur"
  },
  "items": [
    { "grams": 500, "price": 15000000, "quantity": 1 }   // price in SUBUNITS
  ],
  "method": "standard"   // or "instant" — restricts the quote (see §4).
                         // Omit, and every eligible courier is quoted.
}
```

> ⚠️ **`items[].price` must be in subunits (IDR × 100)** — exactly what Shopify's
> checkout sends. `15000000` = Rp 150.000. Quoting from whole rupiah here while
> checkout quotes from subunits would show one price and charge another.

**Response** — `200`
```jsonc
{
  "rates": [
    {
      "service_name": "GRABEXPRESS - INSTANT.",
      "service_code": "BDD-GRAB",
      "description":  "Pasti tiba hari ini",
      "total_price":  6500000,     // SUBUNITS → Rp 65.000
      "currency":     "IDR"
    }
  ]
}
```

Every rate has the five fields above and nothing else. `total_price` is in
subunits — divide by 100 to display.

**Errors**

| Status | Meaning | What to do |
|---|---|---|
| `401` | Bad/missing `X-Storefront-Key` | Fix the key |
| `400` | Bad payload (no destination, no items) | Fix the body |
| `429` | Throttled (60/min per store+IP) | Back off; honour `Retry-After` |
| `503` | Rate engine/courier proxy down | Retryable |
| `200` + `{"rates": []}` | Valid answer: "can't ship this" | Show "no rates", suggest a different cart/address |

---

### 3.2 `POST /api/storefront/geocode`

Turn an address into coordinates, or coordinates into an address. Only needed
for **GrabExpress** (§5). Same header + throttle as `/rates`.

**Forward** — address → coordinates (pin the typed address):
```jsonc
// request
{ "address": "Jl. H.R. Rasuna Said 7, Jakarta Selatan, 12950, Indonesia" }
// response 200
{ "coordinates": { "latitude": -6.2289146, "longitude": 106.8333846 } }
```

**Reverse** — coordinates → address (fill the form when a pin is dropped):
```jsonc
// request
{ "lat": -6.2285501, "lng": 106.8337856 }
// response 200
{ "address": {
    "address1": "Jalan Haji R. Rasuna Said 7",
    "address2": "Kecamatan Setiabudi",
    "city": "Kota Jakarta Selatan",
    "province": "Daerah Khusus Ibukota Jakarta",
    "provinceCode": "JK",          // ← ISO 3166-2 code, ready for Shopify
    "zip": "12950",
    "formatted": "…"
} }
```

`404` if the address/coordinates can't be resolved.

---

### 3.3 `POST /api/storefront/track`

Where the parcel is, from the courier. Same header + throttle as `/rates`
(15/min per store per IP), and scoped to the store the key belongs to.

The **email is required and is the access control**. Order numbers are short
and sequential, so a reference-only lookup would hand every shopper's
destination to anyone counting upwards. A wrong email returns the same `404`
as an order that does not exist — do not build UI that distinguishes them.

```jsonc
// request  — reference is an order number ("#1001" / "1001") or a waybill
{ "reference": "#1001", "email": "shopper@example.com" }

// response 200
{
  "order": { "name": "#1001", "recipient": "Budi Santoso",
             "destination": "Bandung, Jawa Barat", "bookedAt": "2026-07-20 14:49:40" },
  "shipment": {
    "courier": "jne", "courierName": "JNE", "waybill": "JP1234567890",
    "trackingUrl": "https://www.jne.co.id/en/tracking/trace",
    "stage": "out_for_delivery",          // booked|picked_up|in_transit|out_for_delivery|delivered|exception
    "stageLabel": "Out for delivery",
    "source": "courier",                  // "mock" = simulated, say so in the UI
    "note": null,                         // set when a courier has no tracking API (SPX)
    "checkedAt": "2026-08-23 18:21:42",
    "events": [                           // newest first; `at` is null if undated
      { "at": "2026-07-21 22:49:40", "description": "With delivery courier",
        "location": "Bandung", "stage": "out_for_delivery" }
    ]
  },
  "stages": [ { "key": "booked", "label": "Shipment booked" }, … ]   // for your progress bar
}
```

| Status | Meaning | What to show |
|---|---|---|
| `400` | reference or email missing | "Enter both" |
| `401` | bad storefront key | Build/config error, not a shopper error |
| `404` | no such shipment **or** wrong email | One message for both — never two |
| `429` | throttled | Retry after `Retry-After` |

Read `source`: `"mock"` means the waybill was generated in mock mode and the
scans are invented, so a demo store does not present a fictional parcel as
real. Render `stages` rather than hardcoding the list — the app may extend it.

A hosted page is also available at **`GET /track`** if you would rather link
out than build this yourself; it applies exactly the same rules.

---

## 4. Three delivery methods

| Method | `"method"` | Returns | Priced on |
|---|---|---|---|
| **Standard** | `"standard"` | JNE / Ninja / SPX | `postal_code` |
| **Instant (GrabExpress)** | `"instant"` | GRABEXPRESS - INSTANT only | `latitude`/`longitude` |
| **Click and Collect** | `"collect"` | `BDD-CNC` "Click and Collect", Rp 0 | nothing — no carriage |

They are different products, not three views of one list: Standard is parcel
post arriving in days, Instant is a rider dispatched now, and Collect is not a
delivery at all. **Send `method` and the shopper only ever sees the one they
picked.** Omit it and every eligible courier is quoted, which is what happens if
you send nothing.

An unrecognised value restricts nothing rather than returning an empty list —
a stale app build must still leave a shopper able to check out.

### 4.0 Click and Collect

`"collect"` returns exactly one free line and makes **no upstream call at all** —
no zip lookup, no geocode, no Grab quote. It needs no destination, so a cart with
no postcode still gets it.

Two things about it are load-bearing:

- **The price is 0, always.** Store subsidies, weight and cart-total thresholds
  are all inputs to carriage, and there is none.
- **The name is `Click and Collect`.** `AwbService::isClickAndCollect()`
  identifies a pickup order by its shipping-line title, so an order placed on
  this rate routes to in-store collection instead of a courier booking. Renaming
  the rate silently re-routes fulfilment.

> **Why the engine has to return this at all.** On a store that defines no static
> shipping rates, the CarrierService is checkout's *only* source of delivery
> options — a cart with an address and no carrier call has zero delivery groups
> (verified on delamistore, 2026-08-11). So without this line a pickup cart was
> offered all four couriers, and the shopper could be charged for a delivery
> nobody makes. Storefronts that also write the section 9b pickup cart attributes
> should keep doing so: those are what the warehouse reads.

### 4.1 Carrying the choice into Shopify checkout

Restricting your own cart page is half the job. Shopify's checkout calls the
app's CarrierService itself, and that request knows nothing about which tab the
shopper was on — so without a signal it offers **both** methods again, and the
shopper can pick Instant pricing after choosing Standard.

The signal is a **cart line property**. Shopify forwards `items[].properties`
to a carrier service verbatim, but does **not** forward cart-level attributes,
so it has to go on the lines:

```graphql
mutation CartLineAttrs($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
  cartLinesUpdate(cartId: $cartId, lines: $lines) {
    cart { id }
    userErrors { field message }
  }
}
```
```jsonc
// variables — stamp EVERY line, all with the same value
{ "cartId": "gid://shopify/Cart/…",
  "lines": [{ "id": "gid://shopify/CartLine/…",
              "attributes": [{ "key": "_delivery_method", "value": "instant" }] }] }
```

Three rules:

1. **Do it before querying `deliveryGroups`** — that query is what makes Shopify
   call the carrier service. Stamp late and the first quote is unrestricted.
2. **Stamp every line, with the same value.** Lines that disagree describe a
   cart no courier can serve, so the app quotes everything rather than letting
   whichever line sorted first decide.
3. **Keep the leading underscore.** It is Shopify's convention for a property
   that is plumbing, and keeps `_delivery_method` out of the checkout summary,
   the order confirmation and the packing slip.

> **This only governs rates from this app.** Any *static* rates the store
> defines in Shopify (a flat "Standard", free shipping over X) are Shopify's
> own and appear alongside regardless. Remove them from the shipping profile
> if checkout must show nothing but `BDD-*`. delamistore defines none, which is
> why `"collect"` (§4.0) has to come from the engine.

---

## 5. GrabExpress — the coordinate rules (read this)

GrabExpress prices point-to-point, so it needs a **latitude/longitude**. Where
that comes from depends on the surface:

| Surface | How Grab gets coordinates | Your work |
|---|---|---|
| **Native Shopify checkout** | The app **geocodes the shipping address** automatically | **None** — Grab already shows at checkout |
| **Your cart page** | You collect a **map pin** and send it to `/api/storefront/rates` | Add a pin picker (below) |
| **Booking the delivery** | The order's **`coordinates` attribute** (the pin), else geocode | Save the pin as a cart attribute (below) |

So at **native checkout** GrabExpress appears with zero effort. The pin picker
is a **precision upgrade** for the cart and, more importantly, the way to carry
the exact drop-off point onto the order for **dispatch**.

### 5.1 Keep the pin and the address in sync

The pin and the address form **must agree**, or the parcel ships to the typed
address while Grab was priced on a different point. Two directions:

- User **types an address** → forward-geocode → move the pin.
- User **drops/drags the pin** → reverse-geocode → fill the address form.

Always do both. The mock storefront wires exactly this (`syncAddressFromPin`).

### 5.2 Carry the pin onto the order (for dispatch)

Before handing the cart to checkout, write the pin as a **cart attribute**. It
carries to the order as a custom attribute, which is what the app reads to
**book the Grab delivery**:

```
key:   "coordinates"
value: "-6.2285501,106.8337856"     // "lat,lng"
```

Native checkout only ever sees the address, so this is the only way the precise
pin reaches the order.

### 5.3 Carry the pin into the checkout QUOTE (so the fares match)

§5.2 gets the pin onto the order for **dispatch**. It does not affect **pricing**,
because cart attributes are not forwarded to a carrier service — so checkout
still geocodes the typed address and prices Grab from wherever that lands. Your
cart quoted from the pin; checkout quotes from somewhere else.

Stamp the point on the **lines**, next to `_delivery_method` (§4.1), and checkout
prices from exactly the point the cart did:

```jsonc
{ "id": "gid://shopify/CartLine/…",
  "attributes": [
    { "key": "_delivery_method", "value": "instant" },
    { "key": "_delivery_lat",    "value": "-6.35066"  },
    { "key": "_delivery_lng",    "value": "106.83654" }
  ]}
```

Same three rules as §4.1 — stamp every line, same values, before you query
`deliveryGroups`. Send **both** or neither: a lone latitude places nothing, and a
half-stamped pair is ignored. Out-of-range or non-numeric values are ignored too,
falling back to geocoding the address, so a bad stamp can never make a cart
unquotable.

Worth doing even without a pin picker: stamp whatever point your cart quoted
from. Beyond making the fares agree it removes a geocode from the callback,
which is latency inside Shopify's rate-request time budget.

---

## 6. Web headless (Hydrogen / React / any browser)

### 6.1 Rate client

```js
const RATES_API = "https://YOUR-APP-DOMAIN/api/storefront/rates";
const KEY = process.env.PUBLIC_STOREFRONT_KEY;          // pk_…

const GRAMS = { GRAMS: 1, KILOGRAMS: 1000, OUNCES: 28.3495, POUNDS: 453.592 };

// Shopify cart lines → the items[] the endpoint expects (price in SUBUNITS).
function itemsFromCart(lines) {
  return lines.map((line) => {
    const v = line.merchandise ?? {};
    return {
      grams: Math.round((v.weight ?? 0) * (GRAMS[v.weightUnit ?? "GRAMS"] ?? 1)),
      price: Math.round(Number(v.price?.amount ?? 0) * 100),   // ← ×100
      quantity: line.quantity,
    };
  });
}

// `method` is "standard" | "instant" — the tab the shopper is on (§4).
async function getRates(destination, items, method) {
  const res = await fetch(RATES_API, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Storefront-Key": KEY },
    body: JSON.stringify({ destination, items, method }),
  });
  if (!res.ok) throw new Error(`Rates HTTP ${res.status}`);
  const { rates } = await res.json();
  return rates.map((r) => ({
    code: r.service_code,
    title: r.service_name,
    description: r.description,
    amount: r.total_price / 100,     // subunits → whole IDR
    currency: r.currency,
  }));
}

// Carry that same choice into checkout (§4.1). Call this BEFORE querying
// deliveryGroups — that query is what triggers the carrier service.
async function stampDeliveryMethod(storefront, cartId, lineIds, method) {
  await storefront(
    `mutation CartLineAttrs($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
       cartLinesUpdate(cartId: $cartId, lines: $lines) {
         cart { id }
         userErrors { field message }
       }
     }`,
    {
      cartId,
      lines: lineIds.map((id) => ({
        id,
        attributes: [{ key: "_delivery_method", value: method }],
      })),
    },
  );
}
```

**Standard** (zip):
```js
const rates = await getRates(
  { postal_code: "12950", city: "jakarta selatan", province: "JK", country: "ID" },
  itemsFromCart(cart.lines.nodes),
);
```

**Instant** (pin → coordinates, no zip → only GrabExpress):
```js
const rates = await getRates(
  { latitude: String(lat), longitude: String(lng), cityCode: "CGK", address },
  itemsFromCart(cart.lines.nodes),
);
```

### 6.2 Map pin (GrabExpress)

Use any map that gives you a lat/lng — **Leaflet + OpenStreetMap** (free, no
key) is what the mock uses; Google Maps and Mapbox work too. On every pin move,
reverse-geocode through the app and fill the address form:

```js
const GEOCODE_API = RATES_API.replace(/\/rates$/, "/geocode");

async function reverseGeocode(lat, lng) {
  const res = await fetch(GEOCODE_API, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Storefront-Key": KEY },
    body: JSON.stringify({ lat, lng }),
  });
  if (!res.ok) throw new Error(`Reverse geocode HTTP ${res.status}`);
  return (await res.json()).address;   // {address1, city, provinceCode, zip, …}
}

// on pin drag/click/geolocation:
const a = await reverseGeocode(lat, lng);
form.address1.value = a.address1;
form.city.value = a.city;
form.provinceCode.value = a.provinceCode;   // already "JK", not the name
form.zip.value = a.zip;
```

Forward (a "locate my address" button) is the mirror image — POST `{ address }`,
get `{ coordinates }`, move the pin.

### 6.3 Hand off to native Shopify checkout

The cart rate came from the app, so it has no Shopify delivery-option handle —
you attach the address, let Shopify call the CarrierService, and select the
option whose `code` matches. **Strip non-address fields** (lat/lng/cityCode)
before the address mutation.

```js
// 1. Attach the delivery address (address fields ONLY — no lat/lng!)
await storefront(/* graphql */ `
  mutation ($cartId: ID!, $addresses: [CartSelectableAddressInput!]!) {
    cartDeliveryAddressesAdd(cartId: $cartId, addresses: $addresses) {
      cart { id } userErrors { message }
    }
  }`, {
  cartId,
  addresses: [{ address: { deliveryAddress: {
    firstName, lastName, address1, address2, city, provinceCode, zip, countryCode, phone,
  } }, selected: true }],
});

// 2. (GrabExpress) carry the pin onto the order
await storefront(/* graphql */ `
  mutation ($cartId: ID!, $attributes: [AttributeInput!]!) {
    cartAttributesUpdate(cartId: $cartId, attributes: $attributes) {
      cart { id } userErrors { message }
    }
  }`, { cartId, attributes: [{ key: "coordinates", value: `${lat},${lng}` }] });

// 3. Ask Shopify for carrier rates (REQUIRES @defer)
//    query { cart(id) { deliveryGroups(first:1, withCarrierRates:true) @defer {
//      nodes { id deliveryOptions { handle code title estimatedCost { amount } } } } } }

// 4. Match by code and select
const option = deliveryOptions.find((o) => o.code === chosen.code);
await storefront(/* graphql */ `
  mutation ($cartId: ID!, $groupId: ID!, $handle: String!) {
    cartSelectedDeliveryOptionsUpdate(cartId: $cartId,
      selectedDeliveryOptions: [{ deliveryGroupId: $groupId, deliveryOptionHandle: $handle }]) {
      cart { checkoutUrl } userErrors { message }
    }
  }`, { cartId, groupId, handle: option.handle });
```

> **Parity note for GrabExpress:** your cart priced it on the exact **pin**;
> native checkout re-prices it by **geocoding the address**. The two are close
> but rarely identical, and the **checkout price is what's charged**. Treat a
> small difference as expected, not an error. (Standard couriers must match
> exactly — a mismatch there means don't ship.)

---

## 7. Expo mobile app

Expo also uses **native Shopify checkout** (open `cart.checkoutUrl` in a
`WebView` or the system browser). The rate side is the same HTTP API.

**Key differences from web:**

- **No CORS.** Expo sends no `Origin` header, so `cors.storefrontOrigins`
  doesn't apply. The publishable key + per-store throttle are the only bounds —
  which is why the key is never treated as a secret.
- **Pin picker:** use `react-native-maps` (or `expo-location` for
  "use my location"). Everything else is identical.

```js
// rates client — identical logic, React Native fetch
const RATES_API = "https://YOUR-APP-DOMAIN/api/storefront/rates";
const KEY = Config.STOREFRONT_KEY;   // pk_…  (from app config / env)

export async function getRates(destination, items, method) {
  const res = await fetch(RATES_API, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Storefront-Key": KEY },
    body: JSON.stringify({ destination, items, method }),
  });
  if (!res.ok) throw new Error(`Rates HTTP ${res.status}`);
  return (await res.json()).rates;
}
```

**Pin + reverse geocode (Expo):**
```js
import * as Location from "expo-location";

async function useMyLocation() {
  const { status } = await Location.requestForegroundPermissionsAsync();
  if (status !== "granted") return;
  const { coords } = await Location.getCurrentPositionAsync({});
  const address = await reverseGeocode(coords.latitude, coords.longitude); // same /geocode call
  setForm({ ...form, ...address, latitude: coords.latitude, longitude: coords.longitude });
}
```

**Checkout in Expo:**
1. Build the cart with the Storefront API (same as web).
2. Stamp `_delivery_method` on every cart line (§4.1), or checkout offers both
   methods again.
3. Attach the delivery address + `coordinates` attribute (same mutations).
4. Get `cart.checkoutUrl`, open it in a `WebView`.
4. The customer completes native checkout; GrabExpress shows there via the
   address geocode, priced consistently with your cart.

---

## 8. GrabExpress dispatch (what happens after the order)

Once a Grab order is paid, an operator generates its "AWB" in Admin → Orders,
exactly like the other couriers. The app:

1. Reads the drop-off point from the order's **`coordinates`** attribute (the
   pin you saved), or geocodes the address if it's missing.
2. Books the delivery with Grab (dispatches a rider) and fulfils the Shopify
   order with the Grab tracking link.

You don't call any Grab API from the storefront — you only need to **save the
`coordinates` cart attribute** (§5.2) so the precise pin reaches the order.

---

## 9. Checklist

**Web (Hydrogen/React)**
- [ ] Issue a publishable key for the store (Admin → Stores)
- [ ] Add your domain to `cors.storefrontOrigins` on the server
- [ ] Cart page calls `POST /api/storefront/rates` with `items[]` in **subunits**
- [ ] Send `"method"` so the cart shows one delivery method, not both
- [ ] (GrabExpress) map pin + reverse-geocode keep pin ↔ address in sync
- [ ] Handoff: `cartLinesUpdate` (`_delivery_method` on **every** line) → `cartDeliveryAddressesAdd` (address fields only) → `cartAttributesUpdate` (`coordinates`) → `deliveryGroups(withCarrierRates:true)` → `cartSelectedDeliveryOptionsUpdate`
- [ ] Register the store's CarrierService (Admin → Stores) so native checkout has rates

**Expo**
- [ ] Same publishable key (no CORS needed)
- [ ] `POST /api/storefront/rates` from the app
- [ ] (GrabExpress) `expo-location` / `react-native-maps` pin + `/geocode`
- [ ] Build cart → attach address + `coordinates` attribute → open `checkoutUrl` in a WebView

**Gotchas**
- `items[].price` is **subunits (×100)**, not whole rupiah.
- `total_price` in the response is **subunits** — divide by 100 to display.
- Strip `latitude`/`longitude`/`cityCode` before `cartDeliveryAddressesAdd` — Shopify rejects them.
- GrabExpress cart price (pin) vs checkout price (geocoded address) can differ slightly — that's expected; checkout wins.

---

## 10. Reference implementation

`mock-storefront/` is a complete, working example of all of the above:

| File | What it shows |
|---|---|
| `src/rates.js` | rate client, forward/reverse geocode, subunit conversion |
| `src/shopify.js` | cart create/update, `cartDeliveryAddressesAdd` (whitelist), `cartAttributesUpdate`, carrier rates with `@defer` |
| `src/main.js` | Standard/Instant tabs, Leaflet pin picker, pin↔address sync, checkout handoff + parity check |

Run it: `cd mock-storefront && npm i && npm run dev`, then set `.env.local`
(see `.env.example`).

/**
 * Client for the CI4 app's own cart-rate endpoint.
 *
 * This is the call a real headless cart makes. Shopify's Storefront API
 * cannot reach a CarrierService, so a cart page that wants to show shipping
 * prices before checkout has to ask the app directly.
 *
 * The key is publishable on purpose — it identifies and throttles a caller
 * rather than protecting anything, because a rate quote is public data that
 * any shopper can obtain by filling a cart. Rotate it in the CI4 admin
 * (Stores → Edit settings) if a build leaks somewhere it should not.
 */

const API = import.meta.env.VITE_RATES_API_URL;
const KEY = import.meta.env.VITE_STOREFRONT_KEY;

/** Grams per unit of each Shopify weight unit. */
const GRAMS = { GRAMS: 1, KILOGRAMS: 1000, OUNCES: 28.3495, POUNDS: 453.592 };

/**
 * Cart lines in the shape the endpoint expects.
 *
 * `price` must be in SUBUNITS, exactly as Shopify's checkout sends it. The
 * endpoint refuses any other payload shape for this reason: quoting from
 * whole rupiah here while checkout quotes from subunits would show the
 * shopper one price on the cart and charge another at checkout.
 */
export function itemsFromCart(lines) {
  return lines.map((line) => {
    const variant = line.merchandise ?? {};
    const perUnit = GRAMS[variant.weightUnit ?? 'GRAMS'] ?? 1;

    return {
      grams: Math.round((variant.weight ?? 0) * perUnit),
      price: Math.round(Number(variant.price?.amount ?? 0) * 100),
      quantity: line.quantity,
    };
  });
}

/**
 * Geocode an address to coordinates through the app — the same geocoder the
 * rate engine uses for native checkout, so the pin matches what an order would
 * price. Returns {latitude, longitude}. Throws on failure.
 */
export async function geocodeAddress(address) {
  if (!API || !KEY) {
    throw new Error('VITE_RATES_API_URL and VITE_STOREFRONT_KEY must be set in .env.local');
  }

  const url = API.replace(/\/rates\/?$/, '/geocode');
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Storefront-Key': KEY },
    body: JSON.stringify({ address }),
  });

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error({
      401: 'Storefront key rejected.',
      404: 'That address could not be located.',
      429: 'Too many requests — try again in a moment.',
    }[response.status] ?? `Geocode HTTP ${response.status}: ${body.error ?? ''}`);
  }

  return body.coordinates;
}

/**
 * Reverse: coordinates → address fields, so dropping a map pin fills the
 * address form and the two never disagree. Returns {address1, city,
 * provinceCode, zip, formatted, …}. Throws on failure.
 */
export async function reverseGeocode(latitude, longitude) {
  if (!API || !KEY) {
    throw new Error('VITE_RATES_API_URL and VITE_STOREFRONT_KEY must be set in .env.local');
  }

  const url = API.replace(/\/rates\/?$/, '/geocode');
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Storefront-Key': KEY },
    body: JSON.stringify({ lat: latitude, lng: longitude }),
  });

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(body.error ?? `Reverse geocode HTTP ${response.status}`);
  }

  return body.address;
}

/**
 * Address form fields → the destination the rate engine reads.
 *
 * Zip/city drive JNE/Ninja/SPX; latitude/longitude drive GRABEXPRESS, which
 * prices on the exact drop-off point. The coordinates ride along only when the
 * pin was set — the endpoint passes the whole destination through, and the rate
 * engine simply skips Grab when they are absent.
 */
export function destinationFromForm(form) {
  const destination = {
    postal_code: form.zip,
    city: form.city,
    province: form.provinceCode,
    country: form.countryCode,
  };

  if (form.latitude && form.longitude) {
    destination.latitude = form.latitude;
    destination.longitude = form.longitude;
    destination.cityCode = form.cityCode || 'CGK';
    destination.address = [form.address1, form.address2].filter(Boolean).join(', ');
  }

  return destination;
}

/**
 * Ask the app for rates. Returns them already normalised to the same shape
 * Shopify's deliveryOptions use, so the cart renders both identically.
 *
 * `method` restricts the quote to one delivery method ('standard' | 'instant').
 * It is sent explicitly rather than left to be inferred from the presence of
 * coordinates, because the instant form carries a full address too — and
 * because checkout applies the same restriction from the cart line property,
 * so both paths must be told the same thing the same way.
 */
export async function quoteRates(destination, items, method = null) {
  if (!API || !KEY) {
    throw new Error('VITE_RATES_API_URL and VITE_STOREFRONT_KEY must be set in .env.local');
  }

  let response;
  try {
    response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Storefront-Key': KEY },
      body: JSON.stringify({ destination, items, method }),
    });
  } catch (error) {
    // A cross-origin block surfaces here as an opaque "Failed to fetch", with
    // the real reason only in the browser console — worth naming the likely
    // cause rather than passing the useless message straight through.
    throw new Error(
      `Could not reach ${API} (${error.message}). If the app is running, this is `
      + 'usually CORS: add this page\'s origin to cors.storefrontOrigins in the CI4 .env.',
    );
  }

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error({
      401: 'Storefront key rejected. Issue one in the CI4 admin: Stores → Edit settings → Generate key.',
      429: `Rate limited — ${response.headers.get('Retry-After') ?? 'a few'}s until the next quote is allowed.`,
      503: 'The rate engine could not be reached by the app (courier proxy down?). Retryable.',
    }[response.status] ?? `Rate API HTTP ${response.status}: ${body.error ?? ''}`);
  }

  return (body.rates ?? []).map((rate) => ({
    // No Shopify delivery-option handle exists yet — the service_code is what
    // identifies the rate until one is matched at checkout handoff.
    handle: rate.service_code,
    code: rate.service_code,
    title: rate.service_name,
    description: rate.description,
    // The endpoint returns subunits, as Shopify's CarrierService contract does.
    estimatedCost: { amount: rate.total_price / 100, currencyCode: rate.currency },
  }));
}

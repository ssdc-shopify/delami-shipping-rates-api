/**
 * Client for the CI4 app's order-tracking endpoint.
 *
 * The last step of the loop this page demonstrates: the cart quotes a rate,
 * checkout turns it into an order, the admin books a waybill — and this is
 * where the shopper finds out where that parcel is.
 *
 * Same publishable key as the rate call, and scoped to the store it belongs
 * to, so one storefront cannot read another's orders. The email is required
 * because the order number alone is short and sequential: without it this
 * endpoint would hand out every shopper's destination to anyone counting
 * upwards. A wrong email answers exactly like an order that does not exist.
 */

const API = import.meta.env.VITE_RATES_API_URL;
const KEY = import.meta.env.VITE_STOREFRONT_KEY;

/** The tracking endpoint sits beside the rate one. */
const trackUrl = () => API.replace(/\/rates\/?$/, '/track');

/**
 * Look up one shipment.
 *
 * @param {string} reference order number ("#1001" or "1001") or waybill
 * @param {string} email     the address on the order
 * @returns {Promise<{order: object, shipment: object, stages: object[]}>}
 * @throws  {Error} with a message fit to show the shopper
 */
export async function trackOrder(reference, email) {
  if (!API || !KEY) {
    throw new Error('VITE_RATES_API_URL and VITE_STOREFRONT_KEY must be set in .env.local');
  }

  let response;
  try {
    response = await fetch(trackUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Storefront-Key': KEY },
      body: JSON.stringify({ reference, email }),
    });
  } catch (error) {
    // A cross-origin block surfaces here as an opaque "Failed to fetch", with
    // the real reason only in the browser console — worth naming the likely
    // cause rather than passing the useless message straight through.
    throw new Error(
      `Could not reach ${trackUrl()} (${error.message}). If the app is running, this is `
      + 'usually CORS: add this page\'s origin to cors.storefrontOrigins in the CI4 .env.',
    );
  }

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error({
      400: 'Enter both the order number and the email address you ordered with.',
      401: 'Storefront key rejected. Issue one in the CI4 admin: Stores → Edit settings → Generate key.',
      404: 'No shipment found for that number and email. A parcel only appears here '
         + 'once the admin has generated its AWB.',
      429: `Rate limited — ${response.headers.get('Retry-After') ?? 'a few'}s until the next lookup is allowed.`,
    }[response.status] ?? `Tracking API HTTP ${response.status}: ${body.error ?? ''}`);
  }

  return body;
}

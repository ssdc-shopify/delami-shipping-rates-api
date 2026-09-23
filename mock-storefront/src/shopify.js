/**
 * Minimal Shopify Storefront API client + the cart operations needed to
 * exercise the Delami CarrierService callback end to end.
 *
 * All operations validated against the 2026-07 Storefront schema.
 */

const DOMAIN = import.meta.env.VITE_SHOPIFY_DOMAIN;
const TOKEN = import.meta.env.VITE_STOREFRONT_TOKEN;
const VERSION = import.meta.env.VITE_API_VERSION ?? '2026-07';

/**
 * Rates from this app all carry a service_code with this prefix. Shopify
 * also returns the store's own static rates ("Standard", flat rates, free
 * shipping) in the same list; those are filtered out so the cart only ever
 * offers rates the CI4 rate engine produced.
 */
export const RATE_CODE_PREFIX = import.meta.env.VITE_RATE_CODE_PREFIX ?? 'BDD-';

export const isAppRate = (option) => (option?.code ?? '').startsWith(RATE_CODE_PREFIX);

const ENDPOINT = `https://${DOMAIN}/api/${VERSION}/graphql.json`;

export async function storefront(query, variables = {}) {
  const response = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Shopify-Storefront-Access-Token': TOKEN,
    },
    body: JSON.stringify({ query, variables }),
  });

  const body = await response.json();

  if (!response.ok) {
    throw new Error(`Storefront HTTP ${response.status}: ${JSON.stringify(body).slice(0, 300)}`);
  }
  if (body.errors?.length) {
    throw new Error(body.errors.map((e) => e.message).join('; '));
  }

  return body.data;
}

/** Throws when a cart mutation reported userErrors. */
function assertNoUserErrors(payload, label) {
  const errors = payload?.userErrors ?? [];
  if (errors.length) {
    throw new Error(`${label}: ${errors.map((e) => e.message).join('; ')}`);
  }
  return payload;
}

const CART_FIELDS = `
  id
  checkoutUrl
  totalQuantity
  cost {
    subtotalAmount { amount currencyCode }
    totalAmount { amount currencyCode }
  }
  lines(first: 50) {
    nodes {
      id
      quantity
      cost { totalAmount { amount currencyCode } }
      merchandise {
        ... on ProductVariant {
          id
          title
          weight
          weightUnit
          price { amount currencyCode }
          product { title featuredImage { url altText } }
        }
      }
    }
  }
`;

/**
 * Products with every variant, so a card can offer a real variant picker.
 *
 * Variants are resolved in the browser by matching selectedOptions rather than
 * through variantBySelectedOptions, which would be a network round trip per
 * dropdown change. A catalogue this size fits in one request.
 *
 * Weight is fetched per variant and it matters: variants of one product can
 * weigh different amounts, and weight is what the rate engine bills on. A card
 * that priced shipping off the first variant's weight would quote the wrong
 * postage for every other one.
 */
export async function fetchProducts(first = 12) {
  const data = await storefront(
    `query Products($first: Int!) {
       products(first: $first) {
         nodes {
           id
           title
           featuredImage { url altText }
           options { name optionValues { name } }
           variants(first: 100) {
             nodes {
               id
               title
               availableForSale
               weight
               weightUnit
               price { amount currencyCode }
               selectedOptions { name value }
               image { url altText }
             }
           }
         }
       }
     }`,
    { first },
  );

  return data.products.nodes
    .map((product) => {
      const variants = product.variants.nodes;

      // Only an option with more than one value is a choice. Two kinds of
      // option fail that test: the "Title"/"Default Title" pseudo-option
      // Shopify gives a product with no real variants, and a genuine option
      // that happens to have one value — this catalogue's products all carry
      // a Color with a single value beside a real multi-value Size. Neither
      // is worth a dropdown, but both still identify the variant, so they are
      // kept as a fixed part of every lookup instead of being discarded.
      const choices = product.options.filter((option) => option.optionValues.length > 1);
      const fixed = Object.fromEntries(
        product.options
          .filter((option) => option.optionValues.length === 1)
          .map((option) => [option.name, option.optionValues[0].name]),
      );

      return {
        ...product,
        variants,
        choices,
        fixed,
        // What the card shows before anyone touches a dropdown: the first
        // variant that can actually be bought, not simply the first.
        variant: variants.find((v) => v.availableForSale) ?? variants[0],
      };
    })
    // A product whose every variant is sold out cannot be added to a cart.
    .filter((product) => product.variants.some((v) => v.availableForSale));
}

/**
 * The variant matching the chosen values, or undefined when that combination
 * does not exist — normal in a real shop, which can stock "Red / S" and
 * "Blue / L" without stocking "Red / L".
 *
 * The product's single-value options are merged in, so the caller only has to
 * pass what the shopper actually picked.
 *
 * @param selection {Object} option name → chosen value
 */
export function variantFor(product, selection) {
  const wanted = { ...product.fixed, ...selection };

  return product.variants.find((variant) =>
    variant.selectedOptions.every((option) => wanted[option.name] === option.value),
  );
}

export async function createCart(variantId, quantity = 1) {
  const data = await storefront(
    `mutation CartCreate($lines: [CartLineInput!]!) {
       cartCreate(input: {lines: $lines}) {
         cart { ${CART_FIELDS} }
         userErrors { field message }
       }
     }`,
    { lines: [{ merchandiseId: variantId, quantity }] },
  );

  return assertNoUserErrors(data.cartCreate, 'cartCreate').cart;
}

export async function addLine(cartId, variantId, quantity = 1) {
  const data = await storefront(
    `mutation CartLinesAdd($cartId: ID!, $lines: [CartLineInput!]!) {
       cartLinesAdd(cartId: $cartId, lines: $lines) {
         cart { ${CART_FIELDS} }
         userErrors { field message }
       }
     }`,
    { cartId, lines: [{ merchandiseId: variantId, quantity }] },
  );

  return assertNoUserErrors(data.cartLinesAdd, 'cartLinesAdd').cart;
}

/** Set an existing line to an absolute quantity. */
export async function updateLine(cartId, lineId, quantity) {
  const data = await storefront(
    `mutation CartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
       cartLinesUpdate(cartId: $cartId, lines: $lines) {
         cart { ${CART_FIELDS} }
         userErrors { field message }
       }
     }`,
    { cartId, lines: [{ id: lineId, quantity }] },
  );

  return assertNoUserErrors(data.cartLinesUpdate, 'cartLinesUpdate').cart;
}

export async function removeLine(cartId, lineId) {
  const data = await storefront(
    `mutation CartLinesRemove($cartId: ID!, $lineIds: [ID!]!) {
       cartLinesRemove(cartId: $cartId, lineIds: $lineIds) {
         cart { ${CART_FIELDS} }
         userErrors { field message }
       }
     }`,
    { cartId, lineIds: [lineId] },
  );

  return assertNoUserErrors(data.cartLinesRemove, 'cartLinesRemove').cart;
}

/**
 * Attach the shipping address. This is what makes Shopify call the app's
 * CarrierService callback when delivery groups are next queried.
 */
export async function setDeliveryAddress(cartId, address) {
  // Only the fields Shopify's CartDeliveryAddressInput accepts. The Instant
  // form also carries latitude/longitude/cityCode for the app's own rate call;
  // sending those here is rejected ("Field is not defined"), so strip to the
  // real address fields and drop anything blank.
  const ALLOWED = ['firstName', 'lastName', 'company', 'address1', 'address2', 'city', 'provinceCode', 'zip', 'countryCode', 'phone'];
  const deliveryAddress = {};
  for (const field of ALLOWED) {
    if (address[field] !== undefined && address[field] !== '') {
      deliveryAddress[field] = address[field];
    }
  }

  const data = await storefront(
    `mutation CartAddress($cartId: ID!, $addresses: [CartSelectableAddressInput!]!) {
       cartDeliveryAddressesAdd(cartId: $cartId, addresses: $addresses) {
         cart { id }
         userErrors { field message }
         warnings { code message }
       }
     }`,
    {
      cartId,
      addresses: [
        {
          address: { deliveryAddress },
          selected: true,
          oneTimeUse: false,
        },
      ],
    },
  );

  return assertNoUserErrors(data.cartDeliveryAddressesAdd, 'cartDeliveryAddressesAdd');
}

/**
 * Line-item property carrying the chosen delivery method to the CarrierService
 * callback. Must match CarrierRates::METHOD_PROPERTY in the CI4 app.
 *
 * It goes on the *lines*, not on the cart: Shopify's rate request includes
 * `items[].properties` verbatim but does not forward cart-level attributes, so
 * a line property is the only channel that reaches the callback. The leading
 * underscore keeps it out of the checkout summary and the order confirmation.
 */
export const METHOD_PROPERTY = '_delivery_method';

/**
 * Stamp every line in the cart with the chosen delivery method.
 *
 * This is what makes checkout offer one method's rates instead of all of them.
 * It has to happen BEFORE delivery options are queried, because that query is
 * what makes Shopify call the carrier service.
 *
 * Every line is stamped, and with the same value: the CI4 side treats lines
 * that disagree as an unanswerable question and falls back to quoting
 * everything, which would silently undo the filter.
 */
export async function setLineDeliveryMethod(cartId, lineIds, method) {
  const data = await storefront(
    `mutation CartLineAttrs($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
       cartLinesUpdate(cartId: $cartId, lines: $lines) {
         cart {
           id
           lines(first: 50) { nodes { id attributes { key value } } }
         }
         userErrors { field message }
       }
     }`,
    {
      cartId,
      lines: lineIds.map((id) => ({
        id,
        attributes: [{ key: METHOD_PROPERTY, value: method }],
      })),
    },
  );

  return assertNoUserErrors(data.cartLinesUpdate, 'cartLinesUpdate').cart;
}

/**
 * Set custom key-value attributes on the cart. These carry to the order as
 * customAttributes (note attributes), which is where the drop-off pin is
 * stored — the same "coordinates" field the backend reads when booking Grab.
 */
export async function setCartAttributes(cartId, attributes) {
  const data = await storefront(
    `mutation CartAttrs($cartId: ID!, $attributes: [AttributeInput!]!) {
       cartAttributesUpdate(cartId: $cartId, attributes: $attributes) {
         cart { id attributes { key value } }
         userErrors { field message }
       }
     }`,
    { cartId, attributes },
  );

  return assertNoUserErrors(data.cartAttributesUpdate, 'cartAttributesUpdate').cart;
}

/**
 * Carrier-calculated rates.
 *
 * Shopify *requires* @defer for deliveryGroups(withCarrierRates: true) — a
 * plain query is rejected with "must be called with @defer". The reason is
 * that Shopify has to call the carrier service (this app) over the network,
 * so it streams the cart first and the rates second as multipart/mixed.
 *
 * We therefore read the whole multipart body and merge the deferred payload
 * back into the cart.
 */
export async function fetchDeliveryOptions(cartId) {
  const query = `
    query CartRates($cartId: ID!) {
      cart(id: $cartId) {
        ${CART_FIELDS}
        ... @defer(label: "rates") {
          deliveryGroups(first: 5, withCarrierRates: true) {
            nodes {
              id
              selectedDeliveryOption { handle title }
              deliveryOptions {
                handle
                title
                code
                description
                deliveryMethodType
                estimatedCost { amount currencyCode }
              }
            }
          }
        }
      }
    }`;

  const response = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Shopify-Storefront-Access-Token': TOKEN,
      Accept: 'multipart/mixed',
    },
    body: JSON.stringify({ query, variables: { cartId } }),
  });

  if (!response.ok) {
    throw new Error(`Storefront HTTP ${response.status}`);
  }

  const raw = await response.text();
  const chunks = parseMultipart(raw);

  const first = chunks.find((c) => c.data);
  if (!first) {
    const errors = chunks.flatMap((c) => c.errors ?? []);
    throw new Error(errors.map((e) => e.message).join('; ') || 'empty carrier-rate response');
  }

  const cart = first.data.cart;

  // Merge every deferred fragment (the rates) onto the cart.
  for (const chunk of chunks) {
    for (const part of chunk.incremental ?? []) {
      Object.assign(cart, part.data ?? {});
    }
  }

  cart.deliveryGroups ??= { nodes: [] };

  return cart;
}

/**
 * Split a multipart/mixed GraphQL response into its JSON payloads.
 * Boundaries look like `--graphql`, terminated by `--graphql--`.
 */
function parseMultipart(raw) {
  return raw
    .split(/--graphql-?-?/)
    .map((part) => {
      const start = part.indexOf('{');
      if (start === -1) return null;
      try {
        return JSON.parse(part.slice(start).trim());
      } catch {
        return null;
      }
    })
    .filter(Boolean);
}

export async function selectDeliveryOption(cartId, deliveryGroupId, deliveryOptionHandle) {
  const data = await storefront(
    `mutation CartSelectOption($cartId: ID!, $selected: [CartSelectedDeliveryOptionInput!]!) {
       cartSelectedDeliveryOptionsUpdate(cartId: $cartId, selectedDeliveryOptions: $selected) {
         cart {
           ${CART_FIELDS}
           deliveryGroups(first: 5) {
             nodes {
               id
               selectedDeliveryOption {
                 handle
                 title
                 estimatedCost { amount currencyCode }
               }
             }
           }
         }
         userErrors { field message }
       }
     }`,
    { cartId, selected: [{ deliveryGroupId, deliveryOptionHandle }] },
  );

  return assertNoUserErrors(data.cartSelectedDeliveryOptionsUpdate, 'cartSelectedDeliveryOptionsUpdate').cart;
}

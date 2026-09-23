import {
  fetchProducts,
  variantFor,
  createCart,
  addLine,
  updateLine,
  removeLine,
  setDeliveryAddress,
  setCartAttributes,
  setLineDeliveryMethod,
  METHOD_PROPERTY,
  fetchDeliveryOptions,
  selectDeliveryOption,
  isAppRate,
  RATE_CODE_PREFIX,
} from './shopify.js';
import { quoteRates, itemsFromCart, destinationFromForm, geocodeAddress, reverseGeocode } from './rates.js';
import { trackOrder } from './tracking.js';

const el = (id) => document.getElementById(id);
const idr = (amount, currency = 'IDR') =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: 0 })
    .format(Number(amount));

/** Product titles are shop data, but they still reach innerHTML. */
const esc = (value) => String(value ?? '').replace(
  /[&<>"']/g,
  (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
);

const state = { cart: null, group: null, addressed: null, address: null, method: null };

const lines = () => state.cart?.lines?.nodes ?? [];

/** Grams in the cart — the basis the rate engine charges per kg on. */
const cartGrams = () => lines().reduce((sum, line) => {
  const { weight = 0, weightUnit = 'GRAMS' } = line.merchandise ?? {};
  const grams = { GRAMS: 1, KILOGRAMS: 1000, OUNCES: 28.3495, POUNDS: 453.592 }[weightUnit] ?? 1;

  return sum + weight * grams * line.quantity;
}, 0);

function log(message, data) {
  const line = `[${new Date().toLocaleTimeString('id-ID')}] ${message}`;
  el('log').textContent += data === undefined
    ? `${line}\n`
    : `${line}\n${JSON.stringify(data, null, 2)}\n`;
  el('log').scrollTop = el('log').scrollHeight;
}

function show(id) {
  el(id).hidden = false;
}

function fail(where, error) {
  log(`✗ ${where}: ${error.message}`);
  alert(`${where} failed:\n${error.message}`);
}

// ---------------------------------------------------------------- products

async function loadProducts() {
  try {
    const products = await fetchProducts();
    log(`✓ loaded ${products.length} purchasable product(s)`);

    el('products').innerHTML = products
      .map((product, index) => cardHtml(product, index))
      .join('');

    products.forEach((product, index) => wireCard(product, index));
  } catch (error) {
    el('products').innerHTML = `<p class="error">Could not load products: ${error.message}</p>`;
    log(`✗ products: ${error.message}`);
  }
}

/**
 * One product card, with a dropdown per option when the product has any.
 *
 * The card is indexed rather than keyed by product id: a Shopify gid contains
 * slashes, which cannot go in an element id without escaping every lookup.
 */
function cardHtml(product, index) {
  const image = product.variant.image ?? product.featuredImage;

  return `
    <article class="card" data-card="${index}">
      ${image
        ? `<img data-role="image" src="${esc(image.url)}" alt="${esc(image.altText ?? '')}">`
        : '<div class="noimg">no image</div>'}
      <h3>${esc(product.title)}</h3>
      ${product.choices.map((option, optionIndex) => `
        <label class="variant-option">
          <span class="variant-name">${esc(option.name)}</span>
          <select data-option="${optionIndex}" aria-label="${esc(option.name)}">
            ${option.optionValues.map((value) => `
              <option value="${esc(value.name)}">${esc(value.name)}</option>
            `).join('')}
          </select>
        </label>
      `).join('')}
      <p class="price" data-role="price"></p>
      <p class="muted" data-role="weight"></p>
      <button data-role="add">Add to cart</button>
    </article>`;
}

/**
 * Bring a card to life: the dropdowns pick a variant, and price, weight, image
 * and the button all follow it.
 *
 * Price and weight are re-read from the chosen variant rather than rendered
 * once, because both feed the shipping quote — weight is what the rate engine
 * bills per kg, and the cart total decides which couriers are even offered.
 */
function wireCard(product, index) {
  const card = el('products').querySelector(`[data-card="${index}"]`);
  const selects = [...card.querySelectorAll('select[data-option]')];
  const button = card.querySelector('[data-role="add"]');

  // Start on the variant the card was rendered with, so the dropdowns agree
  // with the price shown — that is the first sellable variant, not the first.
  product.variant.selectedOptions.forEach(({ name, value }) => {
    const optionIndex = product.choices.findIndex((option) => option.name === name);
    if (optionIndex !== -1) selects[optionIndex].value = value;
  });

  const render = () => {
    // Only what the shopper picked; variantFor merges in the single-value
    // options, so a product with no dropdowns still resolves its one variant.
    const selection = Object.fromEntries(
      selects.map((select, optionIndex) => [product.choices[optionIndex].name, select.value]),
    );
    const variant = variantFor(product, selection);

    const price = card.querySelector('[data-role="price"]');
    const weight = card.querySelector('[data-role="weight"]');
    const image = card.querySelector('[data-role="image"]');

    if (variant === undefined) {
      // A combination the shop does not stock. Say so plainly instead of
      // leaving a stale price under a selection that cannot be bought.
      price.textContent = '—';
      weight.textContent = 'This combination is not available';
      button.disabled = true;
      button.textContent = 'Unavailable';
      card.dataset.variant = '';
      return;
    }

    price.textContent = idr(variant.price.amount, variant.price.currencyCode);
    weight.textContent = `${variant.weight} ${variant.weightUnit.toLowerCase()}`;
    if (image && variant.image) {
      image.src = variant.image.url;
      image.alt = variant.image.altText ?? '';
    }

    button.disabled = !variant.availableForSale;
    button.textContent = variant.availableForSale ? 'Add to cart' : 'Sold out';
    card.dataset.variant = variant.id;
  };

  selects.forEach((select) => select.addEventListener('change', render));

  button.addEventListener('click', () => {
    if (!card.dataset.variant) return;

    // The variant's own name ("Red / L") is worth logging: it is the thing
    // whose weight and price the shipping quote is about to be built from.
    const variant = product.variants.find((v) => v.id === card.dataset.variant);
    const name = variant.title && variant.title !== 'Default Title'
      ? `${product.title} — ${variant.title}`
      : product.title;

    addToCart(button, card.dataset.variant, name);
  });

  render();
}

// -------------------------------------------------------------------- cart

/**
 * Add to the cart that already exists, creating one only on the first click.
 * Shopify merges a repeat variant into the existing line by itself.
 */
async function addToCart(button, variantId, title) {
  button.disabled = true;

  try {
    state.cart = state.cart === null
      ? await createCart(variantId, 1)
      : await addLine(state.cart.id, variantId, 1);

    log(`✓ "${title}" added — ${state.cart.totalQuantity} item(s)`, {
      subtotal: state.cart.cost.subtotalAmount,
      grams: cartGrams(),
    });

    cartChanged();
    show('step-cart');
    show('step-address');
    if (!state.method) selectMethod('standard');
  } catch (error) {
    fail('add to cart', error);
  } finally {
    button.disabled = false;
  }
}

// -------------------------------------------------------- delivery method

/**
 * Pickup / Standard / Instant. Each is a different rate path:
 *   pickup   — dummy, no rate, no courier
 *   standard — zip → JNE/Ninja/SPX (the map is irrelevant, so it is hidden)
 *   instant  — a map pin → GRABEXPRESS only (coordinates, no zip)
 */
function selectMethod(method) {
  state.method = method;

  ['pickup', 'standard', 'instant'].forEach((m) => {
    el(`pane-${m}`).hidden = m !== method;
  });
  document.querySelectorAll('.method').forEach((b) => {
    b.classList.toggle('active', b.dataset.method === method);
  });

  // Switching method invalidates any rate already shown.
  el('step-rates').hidden = true;
  el('rates').innerHTML = '';
  el('step-checkout').hidden = true;
  state.group = null;

  if (method === 'instant') initPinMap();
}

// ------------------------------------------------------------- drop-off pin

/**
 * A Leaflet pin picker so a real coordinate reaches the rate engine — the one
 * thing GRABEXPRESS needs and a postcode cannot give. OpenStreetMap tiles, no
 * API key, so it works on localhost. The zip-based couriers ignore the pin.
 */
let pinMap = null;
let pinMarker = null;

function coordInput(name) {
  return document.querySelector(`#instant-form [name=${name}]`);
}

function setPin(lat, lng) {
  coordInput('latitude').value = lat.toFixed(7);
  coordInput('longitude').value = lng.toFixed(7);
}

/** Move the marker + inputs, optionally recentring the map. */
function movePin(lat, lng, recenter = false) {
  if (pinMarker) pinMarker.setLatLng([lat, lng]);
  setPin(lat, lng);
  if (recenter && pinMap) pinMap.setView([lat, lng], 16);
}

/**
 * Fill the address form from the pin (reverse geocode) so the address always
 * matches where the pin is — otherwise the order ships to the typed address
 * while Grab was priced on a different point. Personal fields are left alone.
 */
async function syncAddressFromPin(lat, lng) {
  try {
    const a = await reverseGeocode(lat, lng);
    if (!a) return;

    const form = el('instant-form');
    const set = (name, value) => {
      const input = form.querySelector(`[name=${name}]`);
      if (input && value) input.value = value;
    };

    set('address1', a.address1 || a.formatted);
    set('address2', a.address2);
    set('city', a.city);
    set('provinceCode', a.provinceCode);
    set('zip', a.zip);

    log(`✓ address updated from pin: ${a.formatted || a.city || `${lat},${lng}`}`);
  } catch (error) {
    log(`⚠ could not resolve an address for the pin: ${error.message}`);
  }
}

function initPinMap() {
  if (pinMap || !window.L) return;

  const lat = Number(coordInput('latitude').value);
  const lng = Number(coordInput('longitude').value);

  pinMap = L.map('map').setView([lat, lng], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(pinMap);

  pinMarker = L.marker([lat, lng], { draggable: true }).addTo(pinMap);
  pinMarker.on('dragend', () => {
    const p = pinMarker.getLatLng();
    setPin(p.lat, p.lng);
    syncAddressFromPin(p.lat, p.lng);
  });
  pinMap.on('click', (event) => {
    movePin(event.latlng.lat, event.latlng.lng);
    syncAddressFromPin(event.latlng.lat, event.latlng.lng);
  });

  // Geocode the typed address through the app — the same geocoding a native
  // checkout order would use, so the pin matches what the order prices.
  el('use-address').addEventListener('click', async (event) => {
    const button = event.target;
    const form = Object.fromEntries(new FormData(el('instant-form')));
    const address = [form.address1, form.address2, form.city, form.zip, 'Indonesia']
      .filter(Boolean).join(', ');

    button.disabled = true;
    const original = button.textContent;
    button.textContent = 'Locating…';
    try {
      const c = await geocodeAddress(address);
      movePin(c.latitude, c.longitude, true);
      log(`✓ pin set from address: ${Number(c.latitude).toFixed(5)}, ${Number(c.longitude).toFixed(5)}`);
    } catch (error) {
      alert(`Could not locate that address:\n${error.message}`);
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  });

  el('use-location').addEventListener('click', () => {
    if (!navigator.geolocation) {
      alert('This browser has no geolocation.');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        movePin(coords.latitude, coords.longitude, true);
        syncAddressFromPin(coords.latitude, coords.longitude);
        log(`✓ pin set from device location: ${coords.latitude.toFixed(5)}, ${coords.longitude.toFixed(5)}`);
      },
      (error) => alert(`Could not get your location: ${error.message}`),
    );
  });

  // The container was hidden when created, so Leaflet mis-measured it.
  setTimeout(() => pinMap.invalidateSize(), 0);
}

async function changeLine(lineId, quantity) {
  try {
    state.cart = quantity < 1
      ? await removeLine(state.cart.id, lineId)
      : await updateLine(state.cart.id, lineId, quantity);

    log(`✓ cart updated — ${state.cart.totalQuantity} item(s)`, {
      subtotal: state.cart.cost.subtotalAmount,
      grams: cartGrams(),
    });
    cartChanged();
  } catch (error) {
    fail('cart update', error);
  }
}

/**
 * Every quantity change moves the cart total and weight, which is exactly what
 * the rate engine prices on — so any rate already quoted is now stale. Drop it
 * rather than let a rate quoted for a different cart carry into checkout.
 */
function cartChanged() {
  renderCart();

  if (lines().length === 0) {
    el('step-cart').hidden = true;
    el('step-address').hidden = true;
  }

  if (!el('step-rates').hidden || !el('step-checkout').hidden) {
    el('step-rates').hidden = true;
    el('step-checkout').hidden = true;
    el('rates').innerHTML = '';
    state.group = null;
    log('  cart changed — previous rates discarded, ask for rates again');
  }
}

function renderCart(selected) {
  const c = state.cart;
  const grams = cartGrams();

  el('cart-lines').innerHTML = lines().map((line) => {
    const v = line.merchandise ?? {};
    const image = v.product?.featuredImage;
    const name = v.product?.title ?? 'item';
    const variant = v.title && v.title !== 'Default Title' ? v.title : '';

    return `
      <li class="line">
        ${image ? `<img src="${esc(image.url)}" alt="${esc(image.altText ?? '')}">` : '<span class="noimg line-noimg">—</span>'}
        <span class="line-body">
          <span class="line-title">${esc(name)}</span>
          ${variant ? `<span class="muted">${esc(variant)}</span>` : ''}
          <span class="muted">${idr(v.price.amount, v.price.currencyCode)} each</span>
        </span>
        <span class="qty">
          <button data-line="${esc(line.id)}" data-qty="${line.quantity - 1}"
                  aria-label="Decrease quantity">−</button>
          <span class="qty-value">${line.quantity}</span>
          <button data-line="${esc(line.id)}" data-qty="${line.quantity + 1}"
                  aria-label="Increase quantity">+</button>
        </span>
        <span class="line-total">${idr(line.cost.totalAmount.amount, line.cost.totalAmount.currencyCode)}</span>
        <button class="line-remove" data-line="${esc(line.id)}" data-qty="0"
                aria-label="Remove ${esc(name)}">✕</button>
      </li>`;
  }).join('');

  el('cart-lines').querySelectorAll('button[data-line]').forEach((button) => {
    button.addEventListener('click', () => changeLine(button.dataset.line, Number(button.dataset.qty)));
  });

  el('cart-summary').innerHTML = `
    <dl>
      <dt>Cart id</dt><dd class="mono">${esc(c.id.split('/').pop())}</dd>
      <dt>Items</dt><dd>${c.totalQuantity} across ${lines().length} line(s)</dd>
      <dt>Weight</dt><dd>${grams.toLocaleString('id-ID')} g → billed as ${Math.max(1, Math.ceil(grams / 1000))} kg</dd>
      <dt>Subtotal</dt><dd>${idr(c.cost.subtotalAmount.amount, c.cost.subtotalAmount.currencyCode)}</dd>
      ${selected ? `<dt>Shipping</dt><dd>${esc(selected.title)} — ${idr(selected.estimatedCost.amount, selected.estimatedCost.currencyCode)}</dd>` : ''}
      <dt>Total</dt><dd><strong>${idr(c.cost.totalAmount.amount, c.cost.totalAmount.currencyCode)}</strong></dd>
    </dl>`;
}

// ----------------------------------------------------------------- address

/**
 * Quote straight from the CI4 app, not through Shopify.
 *
 * This is the whole point of the cart-rate endpoint: Shopify only calls a
 * CarrierService inside checkout, and the Storefront API cannot reach one, so
 * a cart page that wants to show shipping prices has to ask the app itself.
 * No Shopify round trip, no address stored on the cart, no @defer stream —
 * one POST carrying the publishable key.
 *
 * `address` is the full address to push to Shopify at handoff, or null for
 * paths (instant, pickup) that never hand off to native checkout.
 *
 * `method` restricts the quote to that method's couriers. The same value is
 * stamped on the cart lines at handoff, which is how checkout comes to offer
 * the same restricted list.
 */
async function requestRates(destination, address, button, method) {
  const original = button.textContent;
  button.disabled = true;
  button.textContent = 'Asking the app for rates…';
  state.address = address;

  try {
    const items = itemsFromCart(lines());

    log(`→ POST /api/storefront/rates (${method})`, { destination, items, method });

    const started = performance.now();
    const rates = await quoteRates(destination, items, method);
    const elapsed = Math.round(performance.now() - started);

    log(`✓ app returned ${rates.length} rate(s) in ${elapsed}ms`,
      rates.map((r) => ({ code: r.code, title: r.title, cost: r.estimatedCost.amount })));

    renderCart();
    renderRates({ deliveryOptions: rates });
    show('step-rates');
    el('step-rates').scrollIntoView({ behavior: 'smooth' });
  } catch (error) {
    fail('rate lookup', error);
  } finally {
    button.disabled = false;
    button.textContent = original;
  }
}

document.querySelectorAll('.method').forEach((b) => {
  b.addEventListener('click', () => selectMethod(b.dataset.method));
});

// Standard: full address, zip drives the couriers, no coordinates → no Grab.
el('standard-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = Object.fromEntries(new FormData(event.target));
  requestRates(destinationFromForm(form), form, event.submitter, 'standard');
});

// Instant: the full address is kept for the Shopify handoff; the cart rate is
// priced on the pin coordinates (no zip), so the engine returns just
// GRABEXPRESS. Native checkout then re-prices it by geocoding that address.
el('instant-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = Object.fromEntries(new FormData(event.target));
  const destination = {
    latitude: form.latitude,
    longitude: form.longitude,
    cityCode: form.cityCode || 'CGK',
    address: [form.address1, form.address2, form.city].filter(Boolean).join(', '),
  };
  requestRates(destination, form, event.submitter, 'instant');
});

// Pickup: a dummy option — no rate is quoted and no courier is booked.
el('choose-pickup').addEventListener('click', () => {
  log('ℹ store pickup selected (dummy) — no rate quoted, no courier booked');
  el('checkout').innerHTML = `
    <p><strong>Pickup on store</strong> — Delami Store, Bekasi. <strong>Free.</strong></p>
    <p class="muted">Dummy option for the demo: no shipping rate is quoted and no courier
       is booked. A real build would set a store-pickup delivery line on the order.</p>`;
  show('step-checkout');
  el('step-checkout').scrollIntoView({ behavior: 'smooth' });
});

/**
 * Hand the cart to Shopify with the chosen rate attached.
 *
 * The cart quote came from the app directly, so it carries no Shopify
 * delivery-option handle — and only a handle can be selected on a cart. The
 * address goes up now, Shopify calls the CarrierService itself, and the
 * option whose `code` matches the chosen service_code is the one to select.
 *
 * That round trip also re-prices the cart through the other path, so it is a
 * free parity check: if the two disagree, the shopper was shown a price that
 * checkout would not honour, and that is worth shouting about.
 */
async function handOffToShopify(chosen) {
  // Tell the CarrierService which delivery method this cart is for, BEFORE
  // asking for delivery options — that query is what makes Shopify call the
  // callback. Cart-level attributes are not forwarded to a carrier service,
  // so this rides on the lines, where `items[].properties` carries it.
  //
  // Without it the callback quotes every courier it can, and checkout would
  // offer instant alongside standard no matter which the shopper picked.
  await setLineDeliveryMethod(state.cart.id, lines().map((line) => line.id), state.method);
  log(`✓ cart lines stamped "${METHOD_PROPERTY}": ${state.method} — checkout will offer only ${state.method} rates`);

  if (state.addressed !== JSON.stringify(state.address)) {
    await setDeliveryAddress(state.cart.id, state.address);
    state.addressed = JSON.stringify(state.address);
    log('✓ delivery address attached to the Shopify cart', state.address);
  }

  // Carry the drop-off pin onto the cart so it lands on the ORDER as a custom
  // attribute — the "coordinates" = "lat,lng" field the backend reads when
  // booking GrabExpress (native checkout itself only ever sees the address).
  if (state.address?.latitude && state.address?.longitude) {
    const value = `${state.address.latitude},${state.address.longitude}`;
    await setCartAttributes(state.cart.id, [{ key: 'coordinates', value }]);
    log(`✓ drop-off pin saved to the cart → order attribute "coordinates": ${value}`);
  }

  const cart = await fetchDeliveryOptions(state.cart.id);
  state.cart = cart;

  const group = cart.deliveryGroups.nodes[0];
  state.group = group;

  const all = group?.deliveryOptions ?? [];
  const match = all.find((option) => option.code === chosen.code);
  const isGrab = chosen.code === 'BDD-GRAB' || /^GRABEXPRESS/i.test(chosen.title);

  if (!match) {
    const offered = all.filter(isAppRate).map((o) => o.code).join(', ') || 'none';
    if (isGrab) {
      throw new Error(
        `Shopify's checkout did not offer ${chosen.code}. It offered: ${offered}. `
        + 'Native checkout re-prices GrabExpress by geocoding the shipping address — '
        + 'so this means the address geocodes outside the delivery radius, or the '
        + 'CarrierService/geocoding is not set up on the store yet.',
      );
    }
    throw new Error(
      `Shopify's checkout did not offer ${chosen.code}. It offered: ${offered}. `
      + 'The cart quote and the checkout quote disagree — do not ship this cart.',
    );
  }

  // Parity check, in whole currency units on both sides.
  const cartPrice = Number(chosen.estimatedCost.amount);
  const checkoutPrice = Number(match.estimatedCost.amount);

  if (cartPrice === checkoutPrice) {
    log(`✓ parity: ${chosen.code} is ${idr(cartPrice)} from both the app and Shopify's checkout`);
  } else if (isGrab) {
    // Expected, not an error: the cart priced Grab on your exact pin, while
    // native checkout re-prices it by geocoding the shipping address. Close,
    // rarely identical. The checkout price is what the shopper is charged.
    log(`ℹ ${chosen.code}: cart priced on your pin ${idr(cartPrice)}; checkout re-priced from the geocoded address ${idr(checkoutPrice)} — the checkout price applies`);
  } else {
    log(`✗ PRICE MISMATCH for ${chosen.code}: cart said ${idr(cartPrice)}, checkout says ${idr(checkoutPrice)}`);
    alert(`Price mismatch for ${chosen.code}.\n\nCart: ${idr(cartPrice)}\nCheckout: ${idr(checkoutPrice)}\n\nThe shopper would be charged a different price than they were shown.`);
  }

  return selectDeliveryOption(cart.id, group.id, match.handle);
}

// ------------------------------------------------------------------- rates

/**
 * Rate titles arrive as "SPX - HEMAT." or "JNE - REGULER. (Subsidi Rp 5.000)".
 * Split the courier from the service so each can be styled, and lift the
 * subsidy note out into its own chip — left in the service line it would be
 * lowercased along with the service name.
 */
function splitTitle(title = '') {
  const [courier, ...rest] = title.split(' - ');
  const tail = rest.join(' - ');

  const note = tail.match(/\(([^)]+)\)/)?.[1] ?? '';
  const service = tail.replace(/\([^)]*\)/, '').replace(/\.\s*/, ' ').trim();

  return { courier: courier.trim() || title, service, note };
}

/**
 * What the postage would have cost without the store's subsidy.
 *
 * CarrierService has no compare-at price, so the subsidy amount only reaches
 * us inside the rate title ("… (Subsidi Rp 5.000)"). The charged price is
 * already net of it, so the original is simply charged + subsidy. Returns 0
 * when no subsidy applied, in which case there is nothing to strike through.
 */
function subsidyFrom(note = '') {
  const digits = note.match(/Rp\s*([\d.]+)/)?.[1];

  return digits ? Number(digits.replace(/\./g, '')) : 0;
}

/** "Estimasi sampai 2 hari (Weight: 1Kg)" -> "Estimasi sampai 2 hari". */
function etaFrom(description = '') {
  return (description.split('(')[0] ?? '').trim();
}

function renderRates(group) {
  // Only rates produced by this app's CarrierService callback are offered;
  // the store's own static rates are never shown.
  const options = (group?.deliveryOptions ?? []).filter(isAppRate);

  if (options.length === 0) {
    const returned = (group?.deliveryOptions ?? []).length;
    el('rates').innerHTML = `
      <p class="error">No rates for this address.</p>
      <p class="muted">
        ${returned
          ? `The app returned ${returned} rate(s), but none carried a <code>${RATE_CODE_PREFIX}…</code> service code.`
          : 'The app returned an empty rate list, which is a valid answer meaning "cannot ship this".'}
        The usual causes are a destination the courier proxy has no mapping for, or a cart
        that misses the courier thresholds — SPX is only offered above its minimum cart, JNE
        only below its maximum. Try a larger cart or a different postcode.
      </p>`;
    return;
  }

  const sorted = [...options].sort(
    (a, b) => Number(a.estimatedCost.amount) - Number(b.estimatedCost.amount),
  );
  const cheapest = sorted[0]?.handle;

  el('rates').innerHTML = `
    <div class="rate-list">
      ${sorted.map((option) => {
        const { courier, service, note } = splitTitle(option.title);
        const eta = etaFrom(option.description);
        const subsidy = subsidyFrom(note);
        return `
        <label class="rate" for="rate-${option.handle}">
          <input type="radio" name="rate" id="rate-${option.handle}" value="${option.handle}">
          <span class="rate-mark" aria-hidden="true"></span>
          <span class="rate-body">
            <span class="rate-head">
              <span class="rate-courier">${courier}</span>
              ${service ? `<span class="rate-service">${esc(service)}</span>` : ''}
              ${note ? `<span class="rate-tag rate-subsidy">${esc(note)}</span>` : ''}
              ${option.handle === cheapest ? '<span class="rate-tag">Cheapest</span>' : ''}
            </span>
            ${eta ? `<span class="rate-eta">${eta}</span>` : ''}
            <span class="rate-code">${option.code ?? '—'}</span>
          </span>
          <span class="rate-price">
            ${subsidy > 0
              ? `<s class="rate-was">${idr(Number(option.estimatedCost.amount) + subsidy, option.estimatedCost.currencyCode)}</s>`
              : ''}
            <span class="rate-now">${idr(option.estimatedCost.amount, option.estimatedCost.currencyCode)}</span>
          </span>
        </label>`;
      }).join('')}
    </div>
    <button id="choose" class="primary rate-confirm" disabled>Select a rate to continue</button>`;

  el('rates').querySelectorAll('input[name=rate]').forEach((input) => {
    input.addEventListener('change', () => {
      const button = el('choose');
      button.disabled = false;
      button.textContent = 'Use this rate →';
    });
  });

  el('choose').addEventListener('click', async () => {
    const handle = el('rates').querySelector('input[name=rate]:checked')?.value;
    if (!handle) return;

    const chosen = options.find((option) => option.handle === handle);

    const button = el('choose');
    button.disabled = true;
    button.textContent = 'Handing off to Shopify…';

    try {
      const cart = await handOffToShopify(chosen);
      state.cart = cart;
      const selected = cart.deliveryGroups.nodes[0]?.selectedDeliveryOption;
      log('✓ delivery option selected — it will carry into checkout', selected);

      renderCart(selected);
      button.textContent = 'Rate saved ✓';
      el('checkout').innerHTML = `
        <p>The selected rate is stored on the cart. Continuing to checkout keeps
           both the address and the shipping choice.</p>
        <p class="muted">After you complete this order, it reaches the CI4 admin via the
           <code>orders/create</code> webhook, routed by its <code>service_code</code>.</p>
        <a class="primary" href="${cart.checkoutUrl}" target="_blank" rel="noopener">Continue to checkout →</a>`;
      show('step-checkout');
      el('step-checkout').scrollIntoView({ behavior: 'smooth' });
    } catch (error) {
      // Only re-arm on failure: after a successful save the button reads
      // "Rate saved ✓" and must not invite a second, identical mutation.
      fail('cartSelectedDeliveryOptionsUpdate', error);
      button.disabled = false;
      button.textContent = 'Use this rate →';
    }
  });
}

// ---------------------------------------------------------------- tracking

/** Courier timestamps arrive as 'Y-m-d H:i:s' in Asia/Jakarta. */
const scanTime = (value) => {
  if (!value) return 'Time not reported';
  const at = new Date(value.replace(' ', 'T'));

  return Number.isNaN(at.getTime())
    ? value
    : at.toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

/**
 * The progress rail. An exception is not a position along it, so it lights
 * the whole rail red rather than pretending to be one of the stages.
 */
function railHtml(stages, stage) {
  const problem = stage === 'exception';
  const reached = stages.findIndex((entry) => entry.key === stage);

  return `<div class="rail">${stages.map((entry, index) => {
    const state = problem ? 'warn' : (reached >= 0 && index <= reached ? 'done' : '');

    return `<div class="leg ${state}"><div class="bar"></div><div class="cap">${esc(entry.label)}</div></div>`;
  }).join('')}</div>`;
}

function renderTracking({ order, shipment, stages }) {
  const scans = shipment.events.length === 0
    ? `<p class="muted">${esc(shipment.note ?? 'The courier has not scanned this parcel yet.')}</p>`
    : `<ul class="scans">${shipment.events.map((event, index) => `
        <li class="${index === 0 ? 'head' : ''} ${event.stage === 'exception' ? 'warn' : ''}">
          <div class="scan-text">${esc(event.description)}</div>
          <div class="scan-meta">${esc(scanTime(event.at))}${event.location ? ` · ${esc(event.location)}` : ''}</div>
        </li>`).join('')}</ul>`;

  el('track-result').innerHTML = `
    <div class="track-head">
      <h3>${esc(shipment.stageLabel)}</h3>
      <span class="track-order">Order ${esc(order.name)}</span>
    </div>
    <p class="track-to">
      ${order.destination ? `To <strong>${esc(order.destination)}</strong>` : ''}
      ${order.recipient ? ` · ${esc(order.recipient)}` : ''}
    </p>
    ${railHtml(stages, shipment.stage)}
    <div class="track-awb">
      <span class="muted">${esc(shipment.courierName)}</span>
      <code>${esc(shipment.waybill)}</code>
      <a href="${esc(shipment.trackingUrl)}" target="_blank" rel="noopener noreferrer">
        Open on ${esc(shipment.courierName)} →</a>
    </div>
    ${shipment.source === 'mock'
      ? `<p class="track-note"><strong>Demo shipment.</strong> This waybill was generated with
           <code>couriers.mockAwb</code> on, so the scans are simulated — no courier is
           carrying this parcel.</p>`
      : ''}
    ${scans}`;
}

el('track-form').addEventListener('submit', async (event) => {
  event.preventDefault();

  const form = Object.fromEntries(new FormData(event.target));
  const button = event.target.querySelector('button');

  button.disabled = true;
  button.textContent = 'Asking the courier…';
  el('track-result').innerHTML = '';

  try {
    const result = await trackOrder(form.reference.trim(), form.email.trim());
    log(`✓ tracking ${result.shipment.waybill}: ${result.shipment.stageLabel}`, result.shipment);
    renderTracking(result);
  } catch (error) {
    // Shown in place rather than through fail()'s alert: "no such order" is an
    // ordinary answer to a tracking form, not a failure to report loudly.
    log(`✗ tracking: ${error.message}`);
    el('track-result').innerHTML = `<p class="error">${esc(error.message)}</p>`;
  } finally {
    button.disabled = false;
    button.textContent = 'Track this order →';
  }
});


log(`storefront: ${import.meta.env.VITE_SHOPIFY_DOMAIN} (API ${import.meta.env.VITE_API_VERSION})`);
loadProducts();

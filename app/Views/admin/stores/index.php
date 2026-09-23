<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Stores — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Connect a Shopify store</div>
            <div class="card-body">
                <form method="post" action="<?= site_url('admin/stores/connect') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="shop">Shop URL</label>
                        <input class="form-control" id="shop" name="shop" value="<?= esc(old('shop'), 'attr') ?>"
                               placeholder="my-store.myshopify.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="client_id">Client ID</label>
                        <input class="form-control font-monospace" id="client_id" name="client_id"
                               value="<?= esc(old('client_id'), 'attr') ?>" placeholder="e.g. a1b2c3…" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="client_secret">Client Secret</label>
                        <input class="form-control font-monospace" id="client_secret" name="client_secret"
                               type="password" placeholder="shpss_… (stored encrypted)" required autocomplete="off">
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Authorize with Shopify →</button>
                </form>
                <hr>
                <small class="text-muted">
                    Get the <strong>Client ID</strong> and <strong>Secret</strong> from your app's
                    Settings page in the Shopify Dev Dashboard. Set the app's redirect URL to:<br>
                    <code><?= esc(url_to('shopify-oauth-callback')) ?></code><br>
                    You'll be redirected to Shopify to approve the requested scopes. The secret and
                    the resulting access token are stored encrypted.
                </small>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Connected stores</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Slug</th><th>Shop domain</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($stores as $store): ?>
                        <?php $connected = ! empty($store['access_token']) && empty($store['uninstalled_at']); ?>
                        <tr>
                            <td><code><?= esc($store['slug']) ?></code></td>
                            <td><?= esc($store['shop_domain'] ?? '—') ?></td>
                            <td>
                                <span class="badge bg-<?= $connected ? 'success' : 'secondary' ?>">
                                    <?= $connected ? 'connected' : 'not installed' ?>
                                </span>
                                <?php if (! \App\Models\StoreModel::receivesOrderWebhooks($store)): ?>
                                    <span class="badge bg-warning text-dark" title="This site does not receive this store's order webhooks">
                                        order webhooks off
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" type="button"
                                        data-bs-toggle="modal" data-bs-target="#settings-<?= $store['id'] ?>">
                                    Edit settings
                                </button>
                                <?php if (! empty($store['api_key'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= site_url('shopify/install') . '?store=' . $store['id'] ?>"
                                       title="Re-run the OAuth install for this store">
                                        <?= $connected ? 'Re-authorize' : 'Authorize' ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($stores === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">
                            No stores yet — connect your first store with the form on the left.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php $defaults = config('Couriers'); ?>
<?php foreach ($stores as $store): ?>
    <div class="modal fade" id="settings-<?= $store['id'] ?>" tabindex="-1"
         aria-labelledby="settings-<?= $store['id'] ?>-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post"
                  action="<?= site_url('admin/stores/settings/' . $store['id']) ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="settings-<?= $store['id'] ?>-title">
                        Rate settings — <code><?= esc($store['slug']) ?></code>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <h6 class="text-uppercase text-muted small">Shipping subsidy (subsidi ongkir)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label" for="sub-<?= $store['id'] ?>">Subsidy amount (Rp)</label>
                            <input class="form-control" id="sub-<?= $store['id'] ?>" type="number" min="0" step="1"
                                   name="subsidi_ongkir" value="<?= esc($store['subsidi_ongkir'], 'attr') ?>" required>
                            <div class="form-text">
                                Deducted from every shipping rate, capped at the rate itself. Shipping is
                                free only when this covers the whole cost. 0 disables it.
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="min-<?= $store['id'] ?>">Applies above cart total (Rp)</label>
                            <input class="form-control" id="min-<?= $store['id'] ?>" type="number" min="0" step="1"
                                   name="minimum_order" value="<?= esc($store['minimum_order'], 'attr') ?>" required>
                            <div class="form-text">Cart total must <em>exceed</em> this. 0 = always.</div>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted small">Courier thresholds</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="jne-<?= $store['id'] ?>">JNE max cart (Rp)</label>
                            <input class="form-control" id="jne-<?= $store['id'] ?>" type="number" min="0" step="1"
                                   name="jne_max_cart" value="<?= esc($store['jne_max_cart'] ?? '', 'attr') ?>"
                                   placeholder="<?= esc($defaults->jneMaxCart, 'attr') ?>">
                            <div class="form-text">JNE offered up to this. 0 = always.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="spx-<?= $store['id'] ?>">SPX min cart (Rp)</label>
                            <input class="form-control" id="spx-<?= $store['id'] ?>" type="number" min="0" step="1"
                                   name="spx_min_cart" value="<?= esc($store['spx_min_cart'] ?? '', 'attr') ?>"
                                   placeholder="<?= esc($defaults->spxMinCart, 'attr') ?>">
                            <div class="form-text">SPX offered from this up.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ins-<?= $store['id'] ?>">Insurance from (Rp)</label>
                            <input class="form-control" id="ins-<?= $store['id'] ?>" type="number" min="0" step="1"
                                   name="insurance_min_cart" value="<?= esc($store['insurance_min_cart'] ?? '', 'attr') ?>"
                                   placeholder="<?= esc($defaults->insuranceMinCart, 'attr') ?>">
                            <div class="form-text">Insurance added from this up.</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-uppercase text-muted small">Checkout rates (CarrierService)</h6>
                    <p class="form-text mt-0">
                        Shopify calls this URL during checkout to get shipping rates. The URL is
                        stored on Shopify's side, so it has to be re-registered whenever it
                        changes — after a tunnel restart, for instance. A stale one shows no
                        error: rates simply stop appearing at checkout.
                    </p>
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text">Callback</span>
                        <input class="form-control font-monospace" readonly
                               value="<?= esc(rtrim(config('App')->baseURL, '/') . '/carrier/rates/' . $store['slug'], 'attr') ?>">
                    </div>
                    <?php if (empty($store['access_token'])): ?>
                        <p class="text-muted mb-0"><em>Authorize the store first.</em></p>
                    <?php else: ?>
                        <button class="btn btn-outline-primary btn-sm" type="submit"
                                form="carrier-<?= $store['id'] ?>">
                            Register / re-point carrier service
                        </button>
                        <span class="form-text ms-2">Safe to press repeatedly — it updates in place.</span>
                    <?php endif; ?>

                    <hr class="my-4">

                    <?php $receivesOrders = \App\Models\StoreModel::receivesOrderWebhooks($store); ?>
                    <h6 class="text-uppercase text-muted small">Webhooks on this site</h6>
                    <p class="form-text mt-0">
                        Shopify tells this site when the store uninstalls the app, and — while order
                        webhooks are on — whenever an order is created or updated.
                    </p>
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text">Receiver</span>
                        <input class="form-control font-monospace" readonly
                               value="<?= esc(url_to('shopify-webhook', 'orders-create'), 'attr') ?>">
                    </div>

                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <div class="fw-semibold">
                                Order webhooks
                                <?php if ($receivesOrders): ?>
                                    <span class="badge bg-success">On</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Off</span>
                                <?php endif; ?>
                            </div>
                            <div class="form-text mt-0">
                                A store connected to more than one copy of this app — a development tunnel
                                and production, say — sends every order to each of them. Switch this off to
                                keep this site out; other sites are not affected, and the uninstall webhook
                                stays on. Order lists and Generate AWB read Shopify directly either way.
                            </div>
                        </div>
                        <button class="btn btn-sm text-nowrap <?= $receivesOrders ? 'btn-outline-danger' : 'btn-outline-primary' ?>"
                                type="submit" form="orderhooks-<?= $store['id'] ?>">
                            <?= $receivesOrders ? 'Turn off' : 'Turn on' ?>
                        </button>
                    </div>

                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">Re-register with Shopify</div>
                            <div class="form-text mt-0">
                                Subscribes this site again<?= $receivesOrders ? '' : ' (uninstall only, while order webhooks are off)' ?>.
                                Use it after the site's URL changes, or if orders stop syncing. Safe to press
                                repeatedly.
                            </div>
                        </div>
                        <?php if (empty($store['access_token'])): ?>
                            <span class="form-text text-nowrap mt-0"><em>Authorize the store first.</em></span>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit"
                                    form="webhooks-<?= $store['id'] ?>">
                                Re-register
                            </button>
                        <?php endif; ?>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-uppercase text-muted small">Headless storefront key</h6>
                    <p class="form-text mt-0">
                        Sent as the <code>X-Storefront-Key</code> header by the Hydrogen and Expo
                        cart pages when they call <code>/api/storefront/rates</code>. Per store,
                        because it quotes against this store's thresholds above.
                        Publishable — safe to embed in an app bundle.
                    </p>

                    <?php if (empty($store['storefront_key'])): ?>
                        <p class="text-muted mb-2"><em>No key yet — this store's cart pages cannot fetch rates.</em></p>
                        <!-- form= targets the sibling form below: a form cannot be nested
                             inside the settings form that wraps this modal. -->
                        <button class="btn btn-outline-primary btn-sm"
                                type="submit" form="sfkey-<?= $store['id'] ?>">
                            Generate key
                        </button>
                    <?php else: ?>
                        <div class="input-group mb-2">
                            <input class="form-control font-monospace" readonly
                                   id="sfkey-value-<?= $store['id'] ?>"
                                   value="<?= esc($store['storefront_key'], 'attr') ?>">
                            <button class="btn btn-outline-secondary" type="button"
                                    data-copy-target="sfkey-value-<?= $store['id'] ?>">Copy</button>
                        </div>
                        <button class="btn btn-outline-danger btn-sm"
                                type="submit" form="sfkey-<?= $store['id'] ?>">
                            Rotate key
                        </button>
                        <span class="form-text ms-2">
                            Rotating revokes the current key immediately — every shipped build
                            using it stops quoting until it is updated.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="modal-footer justify-content-between">
                    <small class="text-muted">Leave a threshold blank to use the default shown in the field.</small>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php // Sibling of the settings form, not a child — HTML forbids nesting. ?>
    <form id="sfkey-<?= $store['id'] ?>" method="post" class="d-none"
          action="<?= site_url('admin/stores/storefront-key/' . $store['id']) ?>"
          <?php if (! empty($store['storefront_key'])): ?>
              onsubmit="return confirm('Rotate the storefront key for <?= esc($store['slug'], 'js') ?>?\n\nThe current key stops working immediately, and any Hydrogen or Expo build still using it will stop showing shipping rates.')"
          <?php endif; ?>>
        <?= csrf_field() ?>
    </form>

    <form id="carrier-<?= $store['id'] ?>" method="post" class="d-none"
          action="<?= site_url('admin/stores/carrier/' . $store['id']) ?>">
        <?= csrf_field() ?>
    </form>

    <form id="webhooks-<?= $store['id'] ?>" method="post" class="d-none"
          action="<?= site_url('admin/stores/webhooks/' . $store['id']) ?>">
        <?= csrf_field() ?>
    </form>

    <form id="orderhooks-<?= $store['id'] ?>" method="post" class="d-none"
          action="<?= site_url('admin/stores/order-webhooks/' . $store['id']) ?>"
          <?php if (\App\Models\StoreModel::receivesOrderWebhooks($store)): ?>
              onsubmit="return confirm('Stop receiving <?= esc($store['slug'], 'js') ?> order webhooks on this site?\n\nShipped orders here will stop being refreshed from Shopify. Other sites connected to the store keep receiving them.')"
          <?php endif; ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="enabled" value="<?= \App\Models\StoreModel::receivesOrderWebhooks($store) ? '0' : '1' ?>">
    </form>
<?php endforeach; ?>

<script>
    // Clipboard for the storefront key. execCommand is the fallback because
    // navigator.clipboard is unavailable on insecure origins, which is where
    // this admin runs during local development.
    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.dataset.copyTarget);
            var done  = function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = original; }, 1500);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(field.value).then(done);
                return;
            }

            field.select();
            document.execCommand('copy');
            done();
        });
    });
</script>
<?= $this->endSection() ?>

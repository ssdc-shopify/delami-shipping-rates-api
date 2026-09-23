<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Rate Simulator — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="mb-1">
    <h1 class="h4 mb-1">Rate Simulator</h1>
    <p class="text-muted mb-0">Runs the exact rate engine the Shopify checkout callback uses. Courier lookups go through the widget proxy.</p>
</div>

<?php if ($stores === []): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <div>
            <strong>No store yet.</strong>
            Add a store first — the simulator uses the store's subsidy and rate-handle settings.
        </div>
        <a class="btn btn-primary btn-sm" href="<?= site_url('admin/stores') ?>">Add a store →</a>
    </div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-4">
        <?php
        // Store <option>s are the same in both tabs — build once.
        $storeOptions = static function (array $stores, string $selected): string {
            $html = '<option value="" disabled ' . ($selected === '' ? 'selected' : '') . '>— Select a store —</option>';
            foreach ($stores as $s) {
                $sel  = $selected === $s['slug'] ? 'selected' : '';
                $html .= '<option value="' . esc($s['slug'], 'attr') . '" ' . $sel . '>'
                    . esc($s['name']) . ' (' . esc($s['slug']) . ')</option>';
            }

            return $html;
        };
        $isGrab = ($mode ?? 'standard') === 'grab';
        ?>
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $isGrab ? '' : 'active' ?>" id="tab-standard" data-bs-toggle="tab"
                        data-bs-target="#pane-standard" type="button" role="tab">
                    Standard
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $isGrab ? 'active' : '' ?>" id="tab-grab" data-bs-toggle="tab"
                        data-bs-target="#pane-grab" type="button" role="tab">
                    GRABEXPRESS
                </button>
            </li>
        </ul>
        <div class="tab-content card border-top-0">
            <!-- Standard: zip-based JNE / Ninja / SPX -->
            <div class="tab-pane fade <?= $isGrab ? '' : 'show active' ?>" id="pane-standard" role="tabpanel">
                <div class="card-body">
                    <form method="get" action="<?= site_url('admin/rate-simulator') ?>">
                        <input type="hidden" name="run" value="1">
                        <input type="hidden" name="mode" value="standard">
                        <div class="mb-3">
                            <label class="form-label" for="store_s">Store <span class="text-danger">*</span></label>
                            <select class="form-select" id="store_s" name="store" required>
                                <?= $storeOptions($stores, $input['store']) ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="zip_s">Postal code <span class="text-danger">*</span></label>
                            <input class="form-control" id="zip_s" name="zip" value="<?= esc($input['zip'], 'attr') ?>" placeholder="e.g. 14420" required>
                            <div class="form-text">Resolves JNE / Ninja / SPX by zip.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="city_s">Destination city</label>
                            <input class="form-control" id="city_s" name="city" value="<?= esc($input['city'], 'attr') ?>" placeholder="e.g. jakarta utara">
                        </div>
                        <div class="row">
                            <div class="col mb-3">
                                <label class="form-label" for="weight_s">Weight (grams)</label>
                                <input class="form-control" id="weight_s" name="weight" type="number" min="0" value="<?= esc($input['weight'], 'attr') ?>">
                            </div>
                            <div class="col mb-3">
                                <label class="form-label" for="total_s">Cart total (IDR)</label>
                                <input class="form-control" id="total_s" name="total" type="number" min="0" value="<?= esc($input['total'], 'attr') ?>">
                            </div>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Simulate rates</button>
                    </form>
                </div>
            </div>

            <!-- GRABEXPRESS - INSTANT: coordinate-based, no zip -->
            <div class="tab-pane fade <?= $isGrab ? 'show active' : '' ?>" id="pane-grab" role="tabpanel">
                <div class="card-body">
                    <p class="small text-muted">
                        GRABEXPRESS - INSTANT is priced on the drop-off coordinates, so a
                        zip is not used here. Native Shopify checkout sends no coordinates —
                        a headless cart must collect a map pin and pass lat/lng.
                    </p>
                    <form method="get" action="<?= site_url('admin/rate-simulator') ?>">
                        <input type="hidden" name="run" value="1">
                        <input type="hidden" name="mode" value="grab">
                        <div class="mb-3">
                            <label class="form-label" for="store_g">Store <span class="text-danger">*</span></label>
                            <select class="form-select" id="store_g" name="store" required>
                                <?= $storeOptions($stores, $input['store']) ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col mb-3">
                                <label class="form-label" for="lat_g">Latitude <span class="text-danger">*</span></label>
                                <input class="form-control" id="lat_g" name="lat" value="<?= esc($input['lat'], 'attr') ?>" placeholder="-6.2285501" <?= $isGrab ? 'required' : '' ?>>
                            </div>
                            <div class="col mb-3">
                                <label class="form-label" for="lng_g">Longitude <span class="text-danger">*</span></label>
                                <input class="form-control" id="lng_g" name="lng" value="<?= esc($input['lng'], 'attr') ?>" placeholder="106.8337856" <?= $isGrab ? 'required' : '' ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="citycode_g">City code</label>
                            <input class="form-control" id="citycode_g" name="city_code" value="<?= esc($input['city_code'], 'attr') ?>" placeholder="CGK">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="address_g">Address</label>
                            <input class="form-control" id="address_g" name="address" value="<?= esc($input['address'], 'attr') ?>" placeholder="Plaza ORI, Jl. H. R. Rasuna Said 7 …">
                        </div>
                        <div class="row">
                            <div class="col mb-3">
                                <label class="form-label" for="weight_g">Weight (grams)</label>
                                <input class="form-control" id="weight_g" name="weight" type="number" min="0" value="<?= esc($input['weight'], 'attr') ?>">
                            </div>
                            <div class="col mb-3">
                                <label class="form-label" for="total_g">Cart total (IDR)</label>
                                <input class="form-control" id="total_g" name="total" type="number" min="0" value="<?= esc($input['total'], 'attr') ?>">
                            </div>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Simulate GrabExpress</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="mt-3 small text-muted">
            <?php if ($limits === null): ?>
                Thresholds are set per store under
                <a href="<?= site_url('admin/stores') ?>">Stores</a>. Run a simulation
                to see the ones that applied. Weight bills as whole kg (min 1).
            <?php else: ?>
                <?php $rp = static fn (int $v) => 'Rp' . number_format($v, 0, ',', '.'); ?>
                <strong>This store's thresholds:</strong>
                JNE offered ≤ <?= $limits['jne_max_cart'] > 0 ? $rp($limits['jne_max_cart']) : 'any cart' ?>
                (or as Ninja fallback);
                SPX from <?= $rp($limits['spx_min_cart']) ?>;
                insurance from <?= $rp($limits['insurance_min_cart']) ?>.
                <?php if ($limits['subsidi_ongkir'] > 0): ?>
                    Subsidy <?= $rp($limits['subsidi_ongkir']) ?> on carts over
                    <?= $rp($limits['minimum_order']) ?>.
                <?php else: ?>
                    No shipping subsidy.
                <?php endif; ?>
                Weight bills as whole kg (min 1).
                <a href="<?= site_url('admin/stores') ?>">Change</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><strong>Rate engine error:</strong> <?= esc($error) ?></div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span class="fw-semibold">Rates returned to checkout</span>
                    <span class="text-muted small">
                        <?= count($result) ?> rate(s) · <?= esc($elapsed) ?> ms
                    </span>
                </div>
                <?php
                /**
                 * "SPX - HEMAT. (Subsidi Rp 5.000)" -> ['SPX', 'hemat'].
                 * The note is dropped here and redrawn from the subsidy field,
                 * so the courier and service can be styled apart.
                 */
                $split = static function (string $title): array {
                    $clean = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $title));
                    [$courier, $service] = array_pad(explode(' - ', $clean, 2), 2, '');

                    return [strtoupper(trim($courier)), strtolower(rtrim(trim($service), '.'))];
                };
                $cheapest = $result === [] ? null : min(array_column($result, 'total_price'));
                ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($result as $rate): ?>
                        <?php
                        [$courier, $service] = $split($rate['service_name']);
                        $subsidy = (int) ($rate['subsidy'] ?? 0);
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-3 py-3">
                            <div style="min-width: 0">
                                <div class="d-flex align-items-baseline gap-2 flex-wrap">
                                    <span class="fw-semibold"><?= esc($courier) ?></span>
                                    <?php if ($service !== ''): ?>
                                        <span class="text-muted small"><?= esc($service) ?></span>
                                    <?php endif; ?>
                                    <?php if ($subsidy > 0): ?>
                                        <span class="badge rounded-pill text-primary-emphasis bg-primary-subtle">
                                            Subsidi Rp <?= number_format($subsidy, 0, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($rate['total_price'] === $cheapest): ?>
                                        <span class="badge rounded-pill text-success-emphasis bg-success-subtle">Cheapest</span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mt-1"><?= esc($rate['description']) ?></div>
                                <code class="small text-secondary"><?= esc($rate['service_code']) ?></code>
                            </div>
                            <div class="text-end text-nowrap">
                                <?php if ($subsidy > 0): ?>
                                    <s class="text-muted small d-block">Rp <?= number_format($rate['price_gross'], 0, ',', '.') ?></s>
                                <?php endif; ?>
                                <span class="fs-5 fw-bold">Rp <?= number_format($rate['total_price'] / 100, 0, ',', '.') ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($result === []): ?>
                        <li class="list-group-item text-center text-muted py-4">
                            <?php if (($mode ?? 'standard') === 'grab'): ?>
                                No GrabExpress fare — the drop-off is outside the delivery radius
                                (over <?= esc(config('Couriers')->grabMaxDistanceKm) ?> km from the
                                Bekasi warehouse), or Grab returned nothing. Check the app log for
                                the Grab API response.
                            <?php else: ?>
                                No rates — the zip may be uncovered by all couriers, or the widget proxy returned nothing.
                                Shopify would fall back to backup rates here.
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <details class="mt-3">
                <summary class="text-muted small">Raw JSON (exactly what the callback returns)</summary>
                <?php
                // Mirror CarrierRates: the quote carries keys for these screens
                // that Shopify never sees, so they must not appear here either.
                $wire = array_map(
                    static fn (array $r) => array_intersect_key($r, array_flip(\App\Libraries\Shipping\RateEngine::SHOPIFY_FIELDS)),
                    $result,
                );
                ?>
                <pre class="bg-dark text-light p-3 rounded mt-2"><?= esc(json_encode(['rates' => $wire], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
        <?php else: ?>
            <div class="card"><div class="card-body text-muted text-center py-5">
                Enter a destination and click <strong>Simulate rates</strong> to see what checkout would receive.
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Track Simulator — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
use App\Libraries\Tracking\TrackingService;

$isShopper = $mode === 'shopper';
$config    = config('Couriers');

/** One shipment (the API's shipment object), drawn as the shopper would see it. */
$shipmentCard = static function (array $s) {
    $reached = array_search($s['stage'], TrackingService::STAGE_FLOW, true);
    $problem = $s['stage'] === TrackingService::STAGE_EXCEPTION;
    ob_start(); ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                <span class="fw-semibold"><?= esc($s['courierName']) ?></span>
                <code class="ms-1"><?= esc($s['waybill']) ?></code>
            </span>
            <span class="d-flex gap-1 flex-wrap">
                <?php $sourceStyle = ['courier' => 'success', 'mock' => 'warning text-dark', 'unavailable' => 'secondary'][$s['source']] ?? 'secondary'; ?>
                <span class="badge bg-<?= $sourceStyle ?>" title="Where these scans came from">source: <?= esc($s['source']) ?></span>
                <?php if ($s['stale']): ?>
                    <span class="badge bg-danger" title="The courier did not answer; these are its last scans">stale</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body">
            <h2 class="h5 mb-3 <?= $problem ? 'text-danger' : '' ?>"><?= esc($s['stageLabel']) ?></h2>
            <div class="d-flex gap-1 mb-3">
                <?php foreach (TrackingService::STAGE_FLOW as $i => $stage): ?>
                    <?php $done = ! $problem && $reached !== false && $i <= $reached; ?>
                    <div class="flex-fill text-center">
                        <div class="rounded mb-1" style="height: 6px; background: <?= $problem ? '#dc3545' : ($done ? '#198754' : '#dee2e6') ?>"></div>
                        <div class="small <?= $done ? 'fw-semibold' : 'text-muted' ?>"><?= esc(TrackingService::STAGE_LABELS[$stage]) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (! empty($s['note'])): ?>
                <div class="alert alert-<?= $s['stale'] ? 'danger' : 'secondary' ?> py-2 small"><?= esc($s['note']) ?></div>
            <?php endif; ?>
            <?php if ($s['source'] === 'mock'): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    A <code>MOCK-</code> waybill: these scans are simulated from the booking time, not a courier's.
                </div>
            <?php endif; ?>
            <?php if ($s['events'] === []): ?>
                <p class="text-muted mb-0">No scans.
                    <?php if ($s['source'] === 'unavailable'): ?>
                        The courier returned nothing — check the waybill, the courier's credentials and URL in
                        <code>.env</code>, and that this server can reach the courier.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>When</th><th>Stage</th><th>Scan</th><th>Where</th></tr></thead>
                        <tbody>
                        <?php foreach ($s['events'] as $e): ?>
                            <tr>
                                <td class="text-nowrap small"><?= esc($e['at'] ?? '—') ?></td>
                                <td class="text-nowrap small"><?= esc(TrackingService::STAGE_LABELS[$e['stage']] ?? $e['stage']) ?></td>
                                <td><?= esc($e['description']) ?></td>
                                <td class="small text-muted"><?= esc($e['location']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer small text-muted d-flex justify-content-between flex-wrap gap-2">
            <span>Checked with the courier <?= esc($s['checkedAt']) ?></span>
            <a href="<?= esc($s['trackingUrl'], 'attr') ?>" target="_blank" rel="noopener noreferrer">Open on <?= esc($s['courierName']) ?> ↗</a>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>

<div class="mb-1">
    <h1 class="h4 mb-1">Track Simulator</h1>
    <p class="text-muted mb-0">Runs the exact tracking code behind <code>/track</code> and <code>/api/storefront/track</code>. Read-only — nothing is booked or changed.</p>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $isShopper ? '' : 'active' ?>" data-bs-toggle="tab"
                        data-bs-target="#pane-waybill" type="button" role="tab">By waybill</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $isShopper ? 'active' : '' ?>" data-bs-toggle="tab"
                        data-bs-target="#pane-shopper" type="button" role="tab">As a shopper</button>
            </li>
        </ul>
        <div class="tab-content card border-top-0">
            <div class="tab-pane fade <?= $isShopper ? '' : 'show active' ?>" id="pane-waybill" role="tabpanel">
                <div class="card-body">
                    <p class="small text-muted">Ask a courier about any waybill — how to prove real tracing works before going live.</p>
                    <form method="post" action="<?= site_url('admin/track-simulator') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="waybill">
                        <div class="mb-3">
                            <label class="form-label" for="courier">Courier</label>
                            <select class="form-select" id="courier" name="courier">
                                <?php foreach ($couriers as $key => $name): ?>
                                    <option value="<?= esc($key, 'attr') ?>" <?= $input['courier'] === $key ? 'selected' : '' ?>><?= esc($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="waybill">Waybill <span class="text-danger">*</span></label>
                            <input class="form-control font-monospace" id="waybill" name="waybill" required
                                   value="<?= esc($input['waybill'], 'attr') ?>" placeholder="e.g. JP1234567890">
                            <div class="form-text">GrabExpress: the delivery id (<code>IN-…</code>).</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="fresh" name="fresh" value="1" <?= $input['fresh'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="fresh">Ask the courier now (skip the 5-minute cache)</label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Trace waybill</button>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade <?= $isShopper ? 'show active' : '' ?>" id="pane-shopper" role="tabpanel">
                <div class="card-body">
                    <p class="small text-muted">What a storefront gets from <code>/api/storefront/track</code> — the same lookup, the same email rule, scoped to the store as its key would be.</p>
                    <?php if ($stores === []): ?>
                        <p class="text-muted mb-0">Add a store first under <a href="<?= site_url('admin/stores') ?>">Stores</a>.</p>
                    <?php else: ?>
                        <form method="post" action="<?= site_url('admin/track-simulator') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="mode" value="shopper">
                            <div class="mb-3">
                                <label class="form-label" for="store">Store</label>
                                <select class="form-select" id="store" name="store" required>
                                    <?php foreach ($stores as $s): ?>
                                        <option value="<?= esc($s['slug'], 'attr') ?>" <?= $input['store'] === $s['slug'] ? 'selected' : '' ?>><?= esc($s['name']) ?> (<?= esc($s['slug']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reference">Order or tracking number <span class="text-danger">*</span></label>
                                <input class="form-control" id="reference" name="reference" required
                                       value="<?= esc($input['reference'], 'attr') ?>" placeholder="#1001">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email">Email on the order <span class="text-danger">*</span></label>
                                <input class="form-control" id="email" name="email" type="email" required autocomplete="off"
                                       value="<?= esc($input['email'], 'attr') ?>" placeholder="shopper@example.com">
                            </div>
                            <button class="btn btn-primary w-100" type="submit">Look up as a shopper</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="mt-3 small text-muted">
            Traced: JNE, Ninja Xpress, LJR, GrabExpress. SPX publishes no tracking API, so it only links out.
            Each courier call is capped at <?= esc($config->trackTimeout) ?>s; answers are cached for 5 minutes;
            when a courier stops answering, its last scans (kept 7 days) are shown as stale.
            Mock mode is <?= \App\Libraries\Awb\MockMode::enabled() ? '<strong>on</strong> — new waybills are <code>MOCK-</code>' : 'off' ?>.
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
        <?php endif; ?>

        <?php if ($payload === null && $error === null): ?>
            <div class="card"><div class="card-body text-muted text-center py-5">
                Trace a waybill, or look an order up as a shopper, to see what the courier and the API answer.
            </div></div>
        <?php endif; ?>

        <?php if (! $isShopper && $tracking !== null): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Courier answer</span>
                <span class="text-muted small"><?= count($tracking['events']) ?> scan(s) · <?= esc($elapsed) ?> ms</span>
            </div>

            <?= $shipmentCard($payload) ?>
        <?php endif; ?>

        <?php if ($isShopper && $payload !== null): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">API answer
                    <span class="badge bg-<?= $status === 200 ? 'success' : 'secondary' ?> ms-1">HTTP <?= esc($status) ?></span>
                </span>
                <span class="text-muted small"><?= esc($elapsed) ?> ms</span>
            </div>

            <?php if ($status === 404): ?>
                <div class="card mb-3"><div class="card-body">
                    <p class="mb-1">No order for that reference and email in this store.</p>
                    <p class="text-muted small mb-0">A wrong email and an order that does not exist get this identical answer, by design —
                        the API never tells them apart.</p>
                </div></div>
            <?php else: ?>
                <?php $o = $payload['order']; ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="text-muted small">Order <?= esc($o['name']) ?></div>
                                <div class="h5 mb-1"><?= esc($o['statusLabel']) ?> <code class="small text-muted"><?= esc($o['status']) ?></code></div>
                            </div>
                            <?php if ($o['service'] !== ''): ?>
                                <span class="badge text-bg-light border"><?= esc($o['service']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            <?= esc($o['recipient']) ?><?= $o['destination'] !== '' ? ' · ' . esc($o['destination']) : '' ?>
                            · ordered <?= esc($o['orderedAt'] ?: '—') ?>
                            · handed to the courier <?= esc($o['bookedAt'] ?? 'not yet') ?>
                        </div>
                    </div>
                </div>
                <?php if ($payload['shipment'] !== null): ?>
                    <?= $shipmentCard($payload['shipment']) ?>
                <?php else: ?>
                    <div class="alert alert-secondary">
                        <?= esc($o['note'] ?? 'No parcel yet.') ?>
                        <span class="small text-muted d-block"><code>shipment</code> is <code>null</code> until the order has a tracking number — from this site's airway bill, or from Shopify's fulfillment.</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($payload !== null): ?>
            <details class="mt-2" <?= $isShopper ? 'open' : '' ?>>
                <summary class="text-muted small">
                    <?= $isShopper ? 'Raw JSON — exactly what /api/storefront/track returns' : 'Raw JSON — the shipment object as the API returns it' ?>
                </summary>
                <pre class="bg-dark text-light p-3 rounded mt-2 small"><?= esc(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

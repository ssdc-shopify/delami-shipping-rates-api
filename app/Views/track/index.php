<?= $this->extend('layouts/public') ?>

<?= $this->section('title') ?>Track your order<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
use App\Libraries\Tracking\TrackingService;

/**
 * $tracking is null until a lookup succeeds, so the form is the whole page
 * until there is something to show. The controller always passes it — see
 * Track::page() for why leaving it unset would be unsafe rather than tidy —
 * and these defaults only guard against the view being rendered elsewhere.
 */
$tracking = $tracking ?? null;
$summary  = $summary ?? [];
$error    = $error ?? null;
$pending  = $pending ?? false;

$when = static function (?string $value, string $format = 'D, j M Y · H:i'): string {
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime($value);

    return $ts === false ? '' : date($format, $ts);
};
?>

<style>
    /* Stage rail: where the parcel is, before any of the detail. */
    .rail { display: flex; gap: 6px; margin: 4px 0 22px; }
    .rail .leg { flex: 1; }
    .rail .bar {
        height: 5px; border-radius: 3px; background: #e5e7eb;
    }
    .rail .leg.done .bar { background: #12151a; }
    .rail .leg.warn .bar { background: #b3261e; }
    .rail .cap {
        font-size: 10.5px; letter-spacing: .06em; text-transform: uppercase;
        color: #9ca3af; margin-top: 7px; line-height: 1.25;
    }
    .rail .leg.done .cap { color: #12151a; font-weight: 600; }
    .rail .leg.warn .cap { color: #b3261e; font-weight: 600; }

    /* Timeline: one row per courier scan, newest at the top. */
    .scans { list-style: none; margin: 0; padding: 0; }
    .scans li {
        position: relative;
        padding: 0 0 20px 26px;
        border-left: 2px solid var(--line);
    }
    .scans li:last-child { border-left-color: transparent; padding-bottom: 0; }
    .scans li::before {
        content: ''; position: absolute; left: -7px; top: 4px;
        width: 12px; height: 12px; border-radius: 50%;
        background: #fff; border: 2px solid #cbd5e1;
    }
    .scans li.head::before { background: #12151a; border-color: #12151a; }
    .scans li.warn::before { background: #b3261e; border-color: #b3261e; }
    .scans .text { font-weight: 500; line-height: 1.35; }
    .scans .meta { font-size: 12.5px; color: var(--muted); margin-top: 3px; }

    .awb-line code {
        font-size: 15px; color: #12151a; background: #f1f3f5;
        padding: 3px 8px; border-radius: 6px;
    }
    .kv { font-size: 13.5px; color: var(--muted); }
    .kv strong { color: var(--ink); font-weight: 600; }
</style>

<?php if ($pending): ?>

    <?php // Found, and the email matched — but nothing has been handed to a courier yet. ?>
    <a class="text-secondary text-decoration-none d-inline-block mb-3"
       style="font-size: 13px;" href="<?= site_url('track') ?>">&larr; Track another order</a>

    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                <h1 class="h5 mb-0"><?= esc($summary['statusLabel']) ?></h1>
                <span class="kv">Order <strong><?= esc($summary['orderName']) ?></strong></span>
            </div>
            <p class="kv mb-3">
                <?php if ($summary['orderedAt'] !== ''): ?>
                    Ordered <?= esc($when($summary['orderedAt'])) ?>
                <?php endif; ?>
                <?php if ($summary['destination'] !== ''): ?>
                    · To <strong><?= esc($summary['destination']) ?></strong>
                <?php endif; ?>
            </p>
            <p class="mb-0" style="font-size: 14.5px;">
                <?php if ($summary['status'] === \App\Libraries\Tracking\ShipmentLookup::STATUS_CANCELLED): ?>
                    This order was cancelled, so nothing will be shipped.
                <?php elseif ($summary['status'] === \App\Libraries\Tracking\ShipmentLookup::STATUS_AWAITING_PAYMENT): ?>
                    We are waiting for payment. Your order is prepared once it is paid.
                <?php elseif ($summary['status'] === \App\Libraries\Tracking\ShipmentLookup::STATUS_SHIPPED): ?>
                    Your order has been shipped<?= $summary['service'] !== '' ? ' with ' . esc($summary['service']) : '' ?>.
                <?php else: ?>
                    We are preparing your order<?= $summary['service'] !== '' ? ' for ' . esc($summary['service']) : '' ?>.
                <?php endif; ?>
                <?php if ($summary['note'] !== ''): ?>
                    <?= esc($summary['note']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

<?php elseif ($tracking === null): ?>

    <h1 class="h4 mb-1">Track your order</h1>
    <p class="text-secondary mb-4" style="font-size: 14.5px;">
        Enter your order number or the tracking number from your shipping
        confirmation, along with the email address you ordered with.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="alert alert-danger" style="font-size: 14px;"><?= esc($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-4">
            <form method="post" action="<?= site_url('track') ?>" novalidate>
                <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>">

                <div class="mb-3">
                    <label class="form-label" for="reference">Order or tracking number</label>
                    <input class="form-control" id="reference" name="reference" required
                           autocomplete="off" placeholder="#1001"
                           value="<?= esc($reference ?? '', 'attr') ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control" id="email" name="email" type="email" required
                           autocomplete="email" placeholder="you@example.com"
                           value="<?= esc($email ?? '', 'attr') ?>">
                    <div class="form-text">The address on your order confirmation.</div>
                </div>

                <button class="btn btn-dark w-100 py-2" type="submit">Track my order</button>
            </form>
        </div>
    </div>

<?php else: ?>

    <?php
    $stage     = $tracking['stage'];
    $isProblem = $stage === TrackingService::STAGE_EXCEPTION;
    // How far along the rail to fill. An exception is not a position on the
    // rail, so it lights the whole thing red instead of pretending to be one.
    $reached   = array_search($stage, TrackingService::STAGE_FLOW, true);
    $reached   = $reached === false ? 0 : $reached;
    ?>

    <a class="text-secondary text-decoration-none d-inline-block mb-3"
       style="font-size: 13px;" href="<?= site_url('track') ?>">&larr; Track another order</a>

    <div class="card mb-3">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                <h1 class="h5 mb-0"><?= esc($tracking['stageLabel']) ?></h1>
                <?php if ($summary['orderName'] !== ''): ?>
                    <span class="kv">Order <strong><?= esc($summary['orderName']) ?></strong></span>
                <?php endif; ?>
            </div>

            <p class="kv mb-3">
                <?php if ($summary['destination'] !== ''): ?>
                    To <strong><?= esc($summary['destination']) ?></strong>
                <?php endif; ?>
                <?php if ($summary['recipient'] !== ''): ?>
                    · <?= esc($summary['recipient']) ?>
                <?php endif; ?>
            </p>

            <div class="rail">
                <?php foreach (TrackingService::STAGE_FLOW as $i => $legStage): ?>
                    <?php
                    $class = $isProblem ? 'warn' : ($i <= $reached ? 'done' : '');
                    ?>
                    <div class="leg <?= $class ?>">
                        <div class="bar"></div>
                        <div class="cap"><?= esc(TrackingService::STAGE_LABELS[$legStage]) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="awb-line d-flex align-items-center flex-wrap gap-2 pt-2 border-top">
                <span class="kv"><?= esc($tracking['courierName']) ?></span>
                <code><?= esc($tracking['waybill']) ?></code>
                <a class="btn btn-sm btn-outline-dark ms-auto"
                   href="<?= esc($tracking['trackingUrl'], 'attr') ?>"
                   target="_blank" rel="noopener noreferrer">
                    Open on <?= esc($tracking['courierName']) ?>
                </a>
            </div>
        </div>
    </div>

    <?php if ($tracking['stale']): ?>
        <div class="alert alert-secondary py-2" style="font-size: 13px;">
            <?= esc($tracking['note']) ?>
        </div>
    <?php endif; ?>

    <?php if ($tracking['source'] === 'mock'): ?>
        <div class="alert alert-warning py-2" style="font-size: 13px;">
            <strong>Demo shipment.</strong> This waybill was generated in mock mode, so the
            scans below are simulated — no courier is carrying this parcel.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-4">
            <?php if ($tracking['events'] === []): ?>
                <p class="mb-1" style="font-size: 14.5px;">
                    <?= esc($tracking['note'] ?? 'The courier has not scanned this parcel yet.') ?>
                </p>
                <p class="kv mb-0">
                    Scans usually appear within a few hours of collection.
                    <?php if ($summary['bookedAt'] !== ''): ?>
                        Booked <?= esc($when($summary['bookedAt'])) ?>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <ul class="scans">
                    <?php foreach ($tracking['events'] as $i => $event): ?>
                        <li class="<?= $i === 0 ? 'head' : '' ?> <?= $event['stage'] === TrackingService::STAGE_EXCEPTION ? 'warn' : '' ?>">
                            <div class="text"><?= esc($event['description']) ?></div>
                            <div class="meta">
                                <?= esc($when($event['at']) ?: 'Time not reported') ?>
                                <?php if ($event['location'] !== ''): ?>
                                    · <?= esc($event['location']) ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <p class="kv mt-3 mb-0">Checked with the courier <?= esc($when($tracking['checkedAt'], 'j M, H:i')) ?>.</p>

<?php endif; ?>
<?= $this->endSection() ?>

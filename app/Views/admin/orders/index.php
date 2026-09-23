<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Orders — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/** Keeps the current filters when changing one of them. */
$linkWith = static function (array $overrides) use ($search, $perPage, $store) {
    $params = array_merge([
        'q'        => $search,
        'per_page' => $perPage,
        // Carried on every link: dropping it would silently bounce the
        // operator back to the first store mid-search.
        'store'    => $store['slug'] ?? null,
    ], $overrides);

    return site_url('admin/orders') . '?' . http_build_query(
        array_filter($params, static fn ($v) => $v !== '' && $v !== null)
    );
};
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h1 class="h4 mb-0">Orders <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h1>
        <?php if ($stores !== []): ?>
            <?php // Every list on this page belongs to one store. Shown even for a
                  // single store, so which one is never left to be inferred. ?>
            <form method="get" action="<?= site_url('admin/orders') ?>" class="d-flex align-items-center gap-2">
                <input type="hidden" name="per_page" value="<?= esc($perPage, 'attr') ?>">
                <label class="text-muted small mb-0" for="store-switch">Store</label>
                <select class="form-select form-select-sm w-auto" id="store-switch" name="store"
                        onchange="this.form.submit()">
                    <?php foreach ($stores as $option): ?>
                        <option value="<?= esc($option['slug'], 'attr') ?>"
                            <?= $store !== null && $option['slug'] === $store['slug'] ? 'selected' : '' ?>>
                            <?= esc($option['shop_domain'] ?: $option['slug']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Go</button></noscript>
            </form>
        <?php endif; ?>
    </div>
    <form class="d-flex gap-2" method="get" action="<?= site_url('admin/orders') ?>">
        <input class="form-control" type="search" name="q" value="<?= esc($search, 'attr') ?>"
               aria-label="Search orders"
               placeholder="Search by order, customer, email, postcode or waybill">
        <?php if ($store !== null): ?>
            <input type="hidden" name="store" value="<?= esc($store['slug'], 'attr') ?>">
        <?php endif; ?>
        <input type="hidden" name="per_page" value="<?= esc($perPage, 'attr') ?>">
        <button class="btn btn-outline-primary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-outline-secondary" href="<?= $linkWith(['q' => null]) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($store === null): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <strong>No Shopify store connected yet.</strong>
            Connect a store to receive its orders and print AWBs.
        </div>
        <a class="btn btn-primary btn-sm" href="<?= site_url('admin/stores') ?>">Connect a store →</a>
    </div>
<?php elseif (! \App\Models\StoreModel::receivesOrderWebhooks($store)): ?>
    <div class="alert alert-warning">
        <strong>Order webhooks are off for this store on this site</strong>, so new orders will not
        appear here. Turn them on under <a href="<?= site_url('admin/stores') ?>">Stores → Edit settings</a>.
    </div>
<?php elseif ($total === 0 && $search === ''): ?>
    <div class="alert alert-secondary">
        No orders received yet. Orders appear here as Shopify sends them to this site — anything
        placed before this site started receiving the store's order webhooks is not listed.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-end align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <small class="text-muted">Per page</small>
            <div class="btn-group btn-group-sm">
                <?php foreach (\App\Controllers\Admin\Orders::PER_PAGE_OPTIONS as $option): ?>
                    <a class="btn btn-outline-secondary <?= $perPage === $option ? 'active' : '' ?>"
                       href="<?= $linkWith(['per_page' => $option, 'page' => null]) ?>"><?= $option ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>Order</th><th>Date</th><th>Customer</th><th>Destination</th>
                <th>Chosen rate</th><th>Total</th><th>Payment</th><th>Fulfillment</th><th>AWB</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <?php
                $awb       = $order['awb'];
                $hasAwb    = $awb !== null && ! empty($awb['waybill']);
                $payment   = strtolower((string) ($order['financial_status'] ?: 'unknown'));
                $cancelled = ! empty($order['cancelled_at']);
                ?>
                <tr class="<?= $cancelled ? 'opacity-50' : '' ?>">
                    <td class="fw-semibold">
                        <?php if ($shopUrl !== null && ! empty($order['order_id'])): ?>
                            <a href="<?= esc($shopUrl . '/orders/' . $order['order_id'], 'attr') ?>"
                               target="_blank" rel="noopener"
                               title="Open in Shopify admin">
                                <?= esc($order['order_name'] ?? '—') ?>
                            </a>
                        <?php else: ?>
                            <?= esc($order['order_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><small><?= esc($order['ordered_at'] ? date('d M Y H:i', strtotime($order['ordered_at'])) : '—') ?></small></td>
                    <td><?= esc($order['customer_name'] ?: ($order['ship_name'] ?? '—')) ?></td>
                    <td><small><?= esc(trim(($order['ship_city'] ?? '') . ' ' . ($order['ship_zip'] ?? '')) ?: '—') ?></small></td>
                    <td>
                        <small><?= esc($order['shipping_title'] ?? '—') ?></small>
                        <?php if (! empty($order['shipping_code'])): ?>
                            <code class="d-block text-muted small"><?= esc($order['shipping_code']) ?></code>
                        <?php endif; ?>
                    </td>
                    <td>Rp <?= number_format((float) $order['total_price'], 0, ',', '.') ?></td>
                    <td>
                        <?php if ($cancelled): ?>
                            <span class="badge bg-dark">cancelled</span>
                        <?php else: ?>
                            <span class="badge bg-<?= $payment === 'paid' ? 'success' : 'secondary' ?>">
                                <?= esc(str_replace('_', ' ', $payment)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $fulfillment = strtolower((string) ($order['fulfillment_status'] ?: 'unfulfilled')); ?>
                        <span class="badge bg-<?= $fulfillment === 'fulfilled' ? 'success' : 'warning text-dark' ?>">
                            <?= esc(str_replace('_', ' ', $fulfillment)) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($hasAwb): ?>
                            <code><?= esc($awb['waybill']) ?></code>
                            <small class="text-muted d-block"><?= esc(strtoupper($awb['courier'])) ?></small>
                        <?php elseif ($awb !== null): ?>
                            <span class="badge border bg-light text-dark"><?= esc(strtoupper($awb['courier'])) ?></span>
                            <small class="text-muted d-block">no AWB yet</small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($orders === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No orders match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <?php
    $pageCount = $pager?->getPageCount() ?? 1;
    $current   = $pager?->getCurrentPage() ?? 1;
    $from      = $total === 0 ? 0 : (($current - 1) * $perPage) + 1;
    $to        = min($current * $perPage, $total);
    ?>
    <small class="text-muted">
        <?php if ($total === 0): ?>
            No orders to show.
        <?php else: ?>
            Showing <?= number_format($from) ?>–<?= number_format($to) ?> of <?= number_format($total) ?>
            <?= $pageCount > 1 ? '· page ' . $current . ' of ' . $pageCount : '· single page' ?>
        <?php endif; ?>
    </small>
    <?php if ($pageCount > 1): ?>
        <div><?= $pager->links() ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

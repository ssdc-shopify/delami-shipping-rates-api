<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Dashboard — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($stores === []): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div><strong>No Shopify store connected yet.</strong> Connect one to load orders and print AWBs.</div>
        <a class="btn btn-primary btn-sm" href="<?= site_url('admin/stores') ?>">Connect a store →</a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Stores</span>
        <a class="small" href="<?= site_url('admin/stores') ?>">Edit settings →</a>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <?php
            // The column headers carry the unit, so the cells are bare numbers.
            $rp = static fn (int $v) => number_format($v, 0, ',', '.');

            /**
             * A threshold column is nullable: blank means "inherit the
             * Config\Couriers default". Show the value that actually applies
             * either way, and say when it was inherited rather than set.
             */
            $threshold = static function (array $store, string $column, string $key) use ($rp) {
                $value     = $rp(\App\Models\StoreModel::threshold($store, $column, $key));
                $inherited = ($store[$column] ?? null) === null || $store[$column] === '';

                return $value . ($inherited
                    ? ' <span class="text-muted small">default</span>'
                    : '');
            };
            ?>
            <thead><tr>
                <th>Shop domain</th><th>Status</th>
                <th class="text-end">Subsidy (Rp)</th>
                <th class="text-end">Applies above cart total (Rp)</th>
                <th class="text-end">JNE max cart (Rp)</th>
                <th class="text-end">SPX min cart (Rp)</th>
                <th class="text-end">Insurance from (Rp)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($stores as $store): ?>
                <tr>
                    <td>
                        <?php if (! empty($store['shop_domain'])): ?>
                            <?= esc($store['shop_domain']) ?>
                        <?php else: ?>
                            <?php // Never installed, so there is no domain to show yet. ?>
                            <code class="text-muted"><?= esc($store['slug']) ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (! empty($store['access_token']) && empty($store['uninstalled_at'])): ?>
                            <span class="badge bg-success">connected</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">not installed</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap"><?= $rp((int) $store['subsidi_ongkir']) ?></td>
                    <td class="text-end text-nowrap"><?= $rp((int) $store['minimum_order']) ?></td>
                    <td class="text-end text-nowrap"><?= $threshold($store, 'jne_max_cart', 'jneMaxCart') ?></td>
                    <td class="text-end text-nowrap"><?= $threshold($store, 'spx_min_cart', 'spxMinCart') ?></td>
                    <td class="text-end text-nowrap"><?= $threshold($store, 'insurance_min_cart', 'insuranceMinCart') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($stores === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">
                    No stores yet — <a href="<?= site_url('admin/stores') ?>">connect your first store</a>.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Settings — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-lg-7">
        <h1 class="h4 mb-3">Settings</h1>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Courier mode</span>
                <?php if ($mock): ?>
                    <span class="badge bg-danger">MOCK</span>
                <?php else: ?>
                    <span class="badge bg-success">LIVE</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($mock): ?>
                    <p class="mb-2"><strong>Mock mode is on.</strong> Generate AWB invents a <code>MOCK-…</code>
                        waybill locally and calls no courier. The Shopify order is still fulfilled with that
                        number, so the whole flow can be tested, but the customer is <strong>not</strong> emailed.
                        Labels still print.</p>
                    <p class="text-muted small">Switching to live makes the next Generate AWB book a real shipment
                        with JNE, Ninja Xpress, SPX or GrabExpress (a Grab rider is dispatched at once) and email the
                        customer their shipping confirmation. Waybills already generated in mock mode stay mock.</p>

                    <form method="post" action="<?= site_url('admin/settings/courier-mode') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="live">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm_live" name="confirm_live" value="1" required>
                            <label class="form-check-label" for="confirm_live">
                                I understand that live mode books real shipments and emails customers.
                            </label>
                        </div>
                        <button class="btn btn-danger" type="submit">Switch to live</button>
                    </form>
                <?php else: ?>
                    <p class="mb-2"><strong>Live mode is on.</strong> Generate AWB books a real shipment with the
                        courier the shopper chose, fulfills the Shopify order and emails the customer their
                        shipping confirmation.</p>
                    <p class="text-muted small">Switching back to mock stops all courier calls from the next
                        Generate AWB. Shipments already booked are not cancelled — do that with the courier.</p>

                    <form method="post" action="<?= site_url('admin/settings/courier-mode') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="mock">
                        <button class="btn btn-outline-secondary" type="submit">Switch back to mock</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-footer small text-muted">
                <?php if ($lastChange === null): ?>
                    Never changed — mock is the default for a new installation.
                <?php else: ?>
                    Last changed <?= esc($lastChange['at']) ?> by <?= esc($lastChange['by']) ?>.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

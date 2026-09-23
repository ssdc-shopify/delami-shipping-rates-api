<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>AWB error<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="alert alert-danger">
    <h1 class="h5">Could not generate the AWB</h1>
    <p class="mb-0"><?= esc($message) ?></p>
</div>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/orders') ?>">← Back to orders</a>
<?= $this->endSection() ?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>AWB <?= esc($awb) ?> — GrabExpress</title>
    <?= $this->include('admin/awb/_style') ?>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">Print</button>
</div>

<div class="label">
    <section class="header">
        <strong style="font-size:18px">GRABEXPRESS</strong>
        <strong style="font-size:14px">INSTANT</strong>
    </section>

    <section class="barcode-wrap">
        <div class="caption">Delivery ID</div>
        <img src="<?= $barcode ?>" alt="barcode">
        <div class="awb-number"><?= esc($awb) ?></div>
    </section>

    <section>
        <div class="row-line"><div class="k">Pickup</div><div class="v"><?= esc($couriersConfig->shipperName) ?></div></div>
        <div class="row-line"><div class="k">City</div><div class="v"><?= esc($couriersConfig->shipperCity) ?></div></div>
        <div class="row-line"><div class="k">Phone</div><div class="v"><?= esc($couriersConfig->shipperPhone) ?></div></div>
    </section>

    <section>
        <div class="row-line"><div class="k">Recipient</div><div class="v"><?= esc(strtoupper($consignee)) ?><br><?= esc(strtoupper($address1)) ?></div></div>
        <div class="row-line"><div class="k">City</div><div class="v"><?= esc(strtoupper($city)) ?></div></div>
        <div class="row-line"><div class="k">Zip Code</div><div class="v"><?= esc($zip) ?></div></div>
        <div class="row-line"><div class="k">Phone</div><div class="v"><?= esc($phone) ?></div></div>
    </section>

    <section class="grid-2" style="padding:0">
        <div><strong>Quantity:</strong><br><?= esc($quantity) ?></div>
        <div><strong>Weight:</strong><br><?= esc($weight_kg) ?> Kg</div>
    </section>

    <section class="grid-2" style="padding:0">
        <div><strong>No Order:</strong><br><?= esc($number_id) ?></div>
        <div><strong>Amount:</strong><br>Rp <?= number_format($amount, 0, ',', '.') ?></div>
    </section>

    <section style="text-align:center; font-size:12px; padding-top:6px">
        Pengiriman instan — pasti tiba hari ini
    </section>
</div>

<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>AWB <?= esc($awb) ?> — SPX</title>
    <?= $this->include('admin/awb/_style') ?>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">Print</button>
</div>

<div class="label">
    <section class="header">
        <strong style="font-size:18px">SPX EXPRESS</strong>
        <strong style="font-size:14px"><?= esc($status === 'cnc' ? 'CLICK & COLLECT' : 'STD') ?></strong>
    </section>

    <section class="barcode-wrap">
        <div class="caption">Airwaybill Number</div>
        <img src="<?= $barcode ?>" alt="barcode">
        <div class="awb-number"><?= esc($awb) ?></div>
    </section>

    <section>
        <div class="row-line"><div class="k">Shipper</div><div class="v"><?= esc($couriersConfig->shipperName) ?></div></div>
        <div class="row-line"><div class="k">City</div><div class="v"><?= esc($couriersConfig->shipperCity) ?></div></div>
        <div class="row-line"><div class="k">Phone</div><div class="v"><?= esc($couriersConfig->shipperPhone) ?></div></div>
    </section>

    <section>
        <div class="row-line"><div class="k">Consignee</div><div class="v"><?= esc(strtoupper($consignee)) ?><br><?= esc(strtoupper($address1)) ?></div></div>
        <div class="row-line"><div class="k">City</div><div class="v"><?= esc(strtoupper($city)) ?></div></div>
        <div class="row-line"><div class="k">Province</div><div class="v"><?= esc(strtoupper($province)) ?></div></div>
        <div class="row-line"><div class="k">Zip Code</div><div class="v"><?= esc($zip) ?></div></div>
    </section>

    <section class="grid-2" style="padding:0">
        <div>
            <strong>Sort Code:</strong><br>
            <span class="big"><?= esc($sort_code ?? '—') ?></span>
        </div>
        <div class="qr">
            <img src="<?= $qr ?>" alt="QR Code">
        </div>
    </section>

    <section class="grid-2" style="padding:0">
        <div><strong>Quantity:</strong><br><?= esc($quantity) ?></div>
        <div><strong>Service Type:</strong><br>STD</div>
    </section>

    <section class="grid-2" style="padding:0">
        <div><strong>No Order:</strong><br><?= esc($number_id) ?></div>
        <div><strong>Zone Name:</strong><br><?= esc($zone_name ?? '—') ?></div>
    </section>
</div>

<?= $this->include('admin/awb/_return_slip') ?>

<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>

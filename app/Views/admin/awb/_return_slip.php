<div class="return-slip">
    <h2>RETUR WEBSITE DELAMIBRANDS</h2>
    <div class="cols">
        <div>
            <div>Nomor Order : <?= esc($number_id) ?></div>
            <div>Brand : Executive</div>
            <?php if (($courier ?? '') === 'jne'): ?>
                <div>Resi : RETURNEX<?= esc($number_id) ?></div>
            <?php endif; ?>
        </div>
        <div>
            <div><span class="check"></span>Alasan Personal</div>
            <div><span class="check"></span>Reject</div>
            <div><span class="check"></span>Barang tidak sesuai pesanan</div>
            <div><span class="check"></span>Barang berbeda dengan deskripsi dan foto</div>
        </div>
    </div>
    <div class="warehouse">
        <h3><?= esc($couriersConfig->returnCompany) ?></h3>
        <p><?= esc($couriersConfig->returnAddress) ?></p>
    </div>
</div>

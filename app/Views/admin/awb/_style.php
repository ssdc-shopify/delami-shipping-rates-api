<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 16px; color: #000; }
    .label { width: 390px; border: 2px solid #212121; }
    .label section { border-top: 2px solid #212121; padding: 6px 12px; font-size: 12px; }
    .label section:first-child { border-top: 0; }
    .label .header { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; }
    .label .header img { max-height: 32px; }
    .barcode-wrap { text-align: center; padding: 6px; }
    .barcode-wrap .caption { font-weight: 900; font-size: 15px; }
    .barcode-wrap img { width: 300px; height: auto; }
    .barcode-wrap .awb-number { font-size: 14px; letter-spacing: 2px; }
    .row-line { display: flex; padding: 2px 0; }
    .row-line .k { width: 80px; font-weight: 700; font-size: 13px; }
    .row-line .v { flex: 1; font-size: 13px; }
    .grid-2 { display: flex; text-align: left; }
    .grid-2 > div { width: 50%; padding: 8px 10px; }
    .grid-2 > div:first-child { border-right: 2px solid #212121; }
    .grid-2 strong { font-size: 12px; }
    .grid-2 .big { font-size: 30px; font-weight: 900; }
    .qr img { width: 90px; height: 90px; }
    .return-slip { width: 500px; border: 2px solid #212121; margin: 120px 0 0 -50px; padding: 10px;
                   transform: rotate(-90deg); font-size: 12px; page-break-inside: avoid; }
    .return-slip h2 { text-align: right; font-size: 14px; font-weight: 400; margin: 0 0 8px; }
    .return-slip .cols { display: flex; }
    .return-slip .cols > div { width: 50%; line-height: 23px; }
    .return-slip .check { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin-right: 8px; }
    .return-slip .warehouse h3 { font-size: 20px; margin: 10px 0 4px; }
    .return-slip .warehouse p { line-height: 22px; margin: 0; white-space: pre-line; }
    .no-print { margin-bottom: 12px; }
    @media print { .no-print { display: none; } body { margin: 0; } }
</style>

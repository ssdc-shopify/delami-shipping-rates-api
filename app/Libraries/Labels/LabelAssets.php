<?php

namespace App\Libraries\Labels;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Local barcode/QR generation for shipping labels.
 * Replaces the legacy dependency on external barcode services
 * (bdd.delamibrands.com / barcode.tec-it.com) — labels now render
 * fully offline.
 */
class LabelAssets
{
    /**
     * Code128 barcode as an SVG data URI.
     */
    public static function code128(string $text, int $heightPx = 65): string
    {
        $svg = (new BarcodeGeneratorSVG())->getBarcode(
            $text,
            BarcodeGeneratorSVG::TYPE_CODE_128,
            2,
            $heightPx
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * QR code (error correction H, like the legacy SPX label) as a data URI.
     */
    public static function qr(string $text): string
    {
        $options = new QROptions([
            'eccLevel'   => EccLevel::H,
            'outputBase64' => true,
            'scale'      => 3,
        ]);

        return (new QRCode($options))->render($text);
    }
}

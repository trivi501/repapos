<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Códigos QR en SVG (no necesitan GD ni servicios externos). */
class Qr
{
    public static function svg(string $texto, int $tamano = 160): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd()));
        return $writer->writeString($texto);
    }

    /** Para <img src="...">: funciona en el navegador y dentro de los PDF. */
    public static function dataUri(string $texto, int $tamano = 160): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(static::svg($texto, $tamano));
    }
}

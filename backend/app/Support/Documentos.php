<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\OrdenReparacion;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF del comprobante de una orden y del ticket de una venta.
 * Se usan dentro del sistema y en los enlaces públicos que se mandan por WhatsApp,
 * por eso los textos se leen del taller del documento (no del usuario conectado).
 */
class Documentos
{
    public static function nombreOrden(OrdenReparacion $o): string
    {
        return 'orden-'.str_pad($o->folio, 4, '0', STR_PAD_LEFT).'.pdf';
    }

    public static function nombreVenta(Venta $v): string
    {
        return 'ticket-'.str_pad($v->folio, 5, '0', STR_PAD_LEFT).'.pdf';
    }

    public static function pdfOrden(OrdenReparacion $o): \Barryvdh\DomPDF\PDF
    {
        $o->loadMissing(['cliente' => fn ($q) => $q->withoutGlobalScopes(), 'equipo' => fn ($q) => $q->withoutGlobalScopes(), 'taller']);
        $o->setRelation('sucursal', $o->sucursal()->withoutGlobalScopes()->first());
        $enlace = WhatsApp::enlaceOrden($o);

        return Pdf::loadView('pdf.orden', [
            'orden' => $o,
            'logo' => $o->taller->logoDataUri(),
            'qr' => Qr::dataUri($enlace, 200),
            'enlace' => $enlace,
            'terminos' => Ajuste::valorDe($o->taller_id, 'terminos_orden', Ajuste::TERMINOS_ORDEN),
            'aviso' => Ajuste::valorDe($o->taller_id, 'aviso_recoger', Ajuste::AVISO_RECOGER),
        ])->setOption('isFontSubsettingEnabled', true)   // solo las letras usadas: PDF ligero para WhatsApp
            ->setPaper('letter');
    }

    public static function pdfVenta(Venta $v): \Barryvdh\DomPDF\PDF
    {
        $v->loadMissing('detalles', 'taller', 'user');
        $v->setRelation('sucursal', $v->sucursal()->withoutGlobalScopes()->first());
        $logo = $v->taller->logoDataUri();
        // 80 mm de ancho; el largo crece con los productos
        $alto = 95 + 9 * $v->detalles->count() + ($logo ? 22 : 0) + ((float) $v->descuento > 0 ? 8 : 0);

        return Pdf::loadView('pdf.venta', [
            'venta' => $v,
            'logo' => $logo,
            'pie' => Ajuste::valorDe($v->taller_id, 'pie_ticket_venta', Ajuste::PIE_TICKET_VENTA),
        ])->setOption('isFontSubsettingEnabled', true)
            ->setPaper([0, 0, 80 * 2.8346, $alto * 2.8346]);
    }
}

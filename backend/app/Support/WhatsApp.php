<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\Locatario;
use App\Models\OrdenReparacion;
use App\Models\Telefono;
use App\Models\Venta;
use Illuminate\Support\Collection;

/**
 * Arma enlaces wa.me: abren WhatsApp (en la PC o en el celular) con el mensaje ya escrito,
 * solo falta presionar Enviar. No usa la API de WhatsApp Business.
 */
class WhatsApp
{
    /** 10 dígitos = número nacional, se le antepone la lada del país (52 = México). */
    public static function numero(?string $numero): ?string
    {
        $d = preg_replace('/\D/', '', (string) $numero);
        if ($d === '') {
            return null;
        }

        return strlen($d) === 10 ? preg_replace('/\D/', '', Ajuste::valor('lada', '52')).$d : $d;
    }

    /** Sin número abre WhatsApp para elegir el contacto a mano. */
    public static function link(?string $numero, string $texto): string
    {
        $n = static::numero($numero);

        return 'https://wa.me/'.($n ?? '').'?text='.rawurlencode(static::quitarEmojis($texto));
    }

    /**
     * WhatsApp para Windows cambia los emojis que llegan por enlace por "�" (los acentos sí los respeta),
     * así que se quitan antes de armar el enlace.
     */
    public static function quitarEmojis(string $texto): string
    {
        // solo emojis (no ° © ® ni otros símbolos normales)
        $texto = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $texto);
        $lineas = array_map(fn ($l) => rtrim(preg_replace('/ {2,}/', ' ', $l)), explode("\n", $texto));

        return implode("\n", $lineas);
    }

    /** Página pública (Angular) donde el cliente consulta su orden. */
    public static function enlaceOrden(OrdenReparacion $o): string
    {
        return rtrim(config('app.frontend_url'), '/').'/orden/'.$o->token;
    }

    public static function mensaje(Telefono $t, string $plantilla): string
    {
        return strtr($plantilla, [
            '{locatario}' => $t->locatario->nombre,
            '{modelo}' => $t->modelo,
            '{trabajo}' => $t->trabajo,
            '{total}' => dinero($t->total),
            '{saldo}' => $t->saldo > 0 ? "\nSaldo pendiente: ".dinero($t->saldo) : '',
            '{taller}' => $t->taller?->nombre ?? '',
        ]);
    }

    /** Mensaje para un cliente final sobre su orden de servicio. */
    public static function mensajeOrden(OrdenReparacion $o, string $plantilla): string
    {
        return strtr($plantilla, [
            '{cliente}' => $o->cliente->nombre_completo,
            '{equipo}' => $o->equipo?->nombre ?? '',
            '{folio}' => (string) $o->folio,
            '{estatus}' => $o->estado_texto,
            '{motivo}' => (string) $o->motivo_rechazo,
            '{saldo}' => $o->saldo > 0 ? "\nResta por pagar: ".dinero($o->saldo) : '',
            '{enlace}' => static::enlaceOrden($o),
            '{taller}' => $o->taller?->nombre ?? '',
        ]);
    }

    /** Ticket de la orden (resumen de la nota de recepción) para mandarlo por WhatsApp. */
    public static function ticketOrden(OrdenReparacion $o, string $plantilla): string
    {
        $revision = collect(OrdenReparacion::REVISION)
            ->filter(fn ($texto, $clave) => isset($o->revision[$clave]))
            ->map(fn ($texto, $clave) => $texto.' '.($o->revision[$clave] === 'si' ? 'Sí' : 'No'))
            ->implode(', ');

        return strtr($plantilla, [
            '{taller}' => $o->taller?->nombre ?? '',
            '{sucursal}' => $o->sucursal?->nombre ?? '',
            '{folio}' => (string) $o->folio,
            '{fecha}' => $o->created_at?->format('d/m/Y H:i') ?? '',
            '{cliente}' => $o->cliente->nombre_completo,
            '{equipo}' => trim(($o->equipo?->nombre ?? '').' '.($o->equipo?->color ?? '')),
            '{falla}' => $o->falla_reportada,
            '{revision}' => $revision !== '' ? $revision : '—',
            '{total}' => dinero($o->total),
            '{anticipo}' => dinero($o->pagado),
            '{resta}' => dinero($o->saldo),
            '{estatus}' => $o->estado_texto,
            '{enlace}' => static::enlaceOrden($o),
            '{pdf}' => route('orden.pdf', $o->token),
        ]);
    }

    /** Enlace para avisar del estatus actual de una orden. */
    public static function avisoOrden(OrdenReparacion $o): string
    {
        $plantilla = match ($o->estado) {
            OrdenReparacion::LISTO => Ajuste::valor('mensaje_orden_listo', Ajuste::MENSAJE_ORDEN_LISTO),
            OrdenReparacion::NO_QUEDO => Ajuste::valor('mensaje_orden_no_quedo', Ajuste::MENSAJE_ORDEN_NO_QUEDO),
            OrdenReparacion::RECHAZADO => Ajuste::MENSAJE_ORDEN_RECHAZADO,
            default => Ajuste::valor('mensaje_orden_estatus', Ajuste::MENSAJE_ORDEN_ESTATUS),
        };

        return static::link($o->cliente->telefono, static::mensajeOrden($o, $plantilla));
    }

    public static function enlaceTicketOrden(OrdenReparacion $o): string
    {
        return static::link($o->cliente->telefono, static::ticketOrden($o, Ajuste::valor('ticket_whatsapp', Ajuste::TICKET_WHATSAPP)));
    }

    public static function enlaceTicketVenta(Venta $v): ?string
    {
        if (! $v->cliente_telefono) {
            return null;
        }
        $v->loadMissing('taller', 'detalles');
        $lineas = $v->detalles->map(fn ($d) => "{$d->cantidad} x {$d->nombre}  ".dinero($d->importe))->implode("\n");
        $texto = strtr(Ajuste::valor('ticket_venta_whatsapp', Ajuste::TICKET_VENTA_WHATSAPP), [
            '{taller}' => $v->taller?->nombre ?? '',
            '{folio}' => (string) $v->folio,
            '{fecha}' => $v->created_at->format('d/m/Y H:i'),
            '{cliente}' => $v->cliente_nombre ?: 'cliente',
            '{productos}' => $lineas,
            '{descuento}' => (float) $v->descuento > 0 ? "\nDescuento: -".dinero($v->descuento) : '',
            '{total}' => dinero($v->total),
            '{metodo}' => ucfirst(mb_strtolower($v->metodo)),
            '{pie}' => Ajuste::valor('pie_ticket_venta', Ajuste::PIE_TICKET_VENTA),
            '{pdf}' => route('venta.pdf', $v->token),
        ]);

        return static::link($v->cliente_telefono, $texto);
    }

    /** Aviso de LISTO o NO QUEDÓ según el estatus del teléfono; null si sigue en taller. */
    public static function aviso(Telefono $t): ?string
    {
        $plantilla = match ($t->resultado) {
            Telefono::LISTO => Ajuste::valor('mensaje_listo', Ajuste::MENSAJE_LISTO),
            Telefono::NO_QUEDO => Ajuste::valor('mensaje_no_quedo', Ajuste::MENSAJE_NO_QUEDO),
            default => null,
        };

        return $plantilla === null ? null : static::link($t->locatario->whatsapp, static::mensaje($t, $plantilla));
    }

    /** Teléfonos ya entregados que el locatario todavía debe, más antiguos primero. */
    public static function entregadosQueDebe(Locatario $l): Collection
    {
        return $l->telefonos()->conSaldo()->where('entregado', true)->orderBy('fecha_recibido')->orderBy('id')->get();
    }

    /** Estado de cuenta del locatario: teléfonos entregados que debe y productos que se llevó a crédito. */
    public static function cuenta(Locatario $l, Collection $telefonos, float $aFavor, ?Collection $ventas = null): string
    {
        $total = 0;
        $lineas = ["Hola {$l->nombre}", 'Te comparto tu cuenta pendiente de pago:', ''];
        if ($telefonos->isNotEmpty()) {
            $lineas[] = '*Equipos entregados*';
            foreach ($telefonos as $t) {
                $total += $t->saldo;
                $linea = '• '.$t->fecha_recibido->format('d/m').'  '.$t->modelo
                    .($t->trabajo !== '' ? " ({$t->trabajo})" : '').': '.dinero($t->saldo);
                if ((float) $t->pagado > 0) {
                    $linea .= '  _(abonado '.dinero($t->pagado).')_';
                }
                $lineas[] = $linea;
            }
            $lineas[] = '';
        }
        if ($ventas && $ventas->isNotEmpty()) {
            $lineas[] = '*Productos a crédito*';
            foreach ($ventas as $v) {
                $total += $v->saldo;
                $productos = $v->detalles->filter(fn ($d) => $d->cantidad > $d->devuelto)
                    ->map(fn ($d) => ($d->cantidad - $d->devuelto).' '.$d->nombre)->implode(', ');
                $linea = '• '.$v->created_at->format('d/m').'  Venta '.$v->folio.' ('.$productos.'): '.dinero($v->saldo);
                if ((float) $v->pagado > 0) {
                    $linea .= '  _(abonado '.dinero($v->pagado).')_';
                }
                $lineas[] = $linea;
            }
            $lineas[] = '';
        }
        if ($aFavor > 0) {
            $lineas[] = 'Suma: '.dinero($total);
            $lineas[] = 'Tu saldo a favor: -'.dinero(min($aFavor, $total));
            $total = max(0, $total - $aFavor);
        }
        $lineas[] = '*Total a pagar: '.dinero($total).'*';
        $lineas[] = '¡Gracias!';

        return implode("\n", $lineas);
    }
}

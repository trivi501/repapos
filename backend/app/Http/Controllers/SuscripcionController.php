<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SuscripcionController extends Controller
{
    public function show(Request $r)
    {
        $taller = $r->user()->taller;
        $mensaje = 'Hola, quiero activar mi plan de '.config('venta.producto')." para el taller \"{$taller->nombre}\" (cuenta #{$taller->id}).";

        return response()->json([
            'taller' => ['id' => $taller->id, 'nombre' => $taller->nombre],
            'vigente' => $taller->vigente(),
            'estado_texto' => $taller->estadoTexto(),
            'dias_restantes' => $taller->diasRestantes(),
            'precio_mensual' => config('venta.precio_mensual'),
            'precio_anual' => config('venta.precio_anual'),
            'moneda' => config('venta.moneda'),
            'datos_pago' => config('venta.datos_pago'),
            'contacto' => config('venta.whatsapp')
                ? 'https://wa.me/'.preg_replace('/\D/', '', config('venta.whatsapp')).'?text='.rawurlencode($mensaje)
                : null,
        ]);
    }
}

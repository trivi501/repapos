<?php

namespace App\Http\Controllers;

use App\Models\Locatario;
use App\Models\Telefono;
use App\Support\WhatsApp;

class ResumenController extends Controller
{
    public function index()
    {
        $filas = Locatario::with(['telefonos', 'ventas' => fn ($q) => $q->conSaldo()->with('detalles')->orderBy('created_at')])
            ->withSum('creditos as a_favor', 'monto')->orderBy('nombre')->get()
            ->map(function (Locatario $l) {
                $t = $l->telefonos;
                $pendientes = $t->where('entregado', false);
                $deben = WhatsApp::entregadosQueDebe($l);
                $ventas = $l->ventas;   // ventas a crédito con saldo
                $aFavor = round((float) $l->a_favor, 2);
                $hayCuenta = $deben->isNotEmpty() || $ventas->isNotEmpty();

                return [
                    'locatario' => ['id' => $l->id, 'nombre' => $l->nombre, 'whatsapp' => $l->whatsapp],
                    'telefonos' => $t->count(),
                    'en_taller' => $pendientes->where('resultado', Telefono::EN_TALLER)->count(),
                    'listos' => $pendientes->where('resultado', Telefono::LISTO)->count(),
                    'no_quedaron' => $pendientes->where('resultado', Telefono::NO_QUEDO)->count(),
                    'total' => $t->where('resultado', '!=', Telefono::NO_QUEDO)->sum(fn ($x) => (float) $x->total),
                    'pagado' => $t->sum(fn ($x) => (float) $x->pagado),
                    'debe' => $t->sum(fn ($x) => max(0, $x->saldo)) + $ventas->sum(fn ($v) => $v->saldo),
                    'debe_ventas' => $ventas->sum(fn ($v) => $v->saldo),
                    'a_favor' => $aFavor,
                    'entregados_deben' => $deben->count() + $ventas->count(),
                    'cuenta_url' => $hayCuenta ? WhatsApp::link($l->whatsapp, WhatsApp::cuenta($l, $deben, $aFavor, $ventas)) : null,
                ];
            })
            ->filter(fn ($f) => $f['telefonos'] > 0 || $f['a_favor'] != 0 || $f['debe_ventas'] > 0)
            ->values();

        return response()->json($filas);
    }
}

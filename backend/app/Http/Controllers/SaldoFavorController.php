<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Locatario;
use App\Models\Pago;
use App\Models\Salida;
use App\Models\Telefono;
use App\Models\Venta;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saldos a favor de los locatarios.
 * - Depósito: entra a la caja del día (como un cobro).
 * - Aplicado: se descuenta de sus teléfonos que deben; NO es dinero nuevo en caja.
 * - Devuelto: se le regresa el dinero; sale de la caja como salida.
 */
class SaldoFavorController extends Controller
{
    public function index(Request $r)
    {
        $locatarios = Locatario::withSum('creditos as a_favor', 'monto')->orderBy('nombre')->get();
        $lista = $r->boolean('todos') ? $locatarios : $locatarios->filter(fn ($l) => round((float) $l->a_favor, 2) != 0);
        $elegido = $r->filled('locatario') ? $locatarios->firstWhere('id', $r->integer('locatario')) : null;
        if ($elegido && ! $lista->contains('id', $elegido->id)) {
            $lista->push($elegido);
        }

        $plan = $elegido ? $this->plan($elegido) : [];

        return response()->json([
            'lista' => $lista->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre, 'a_favor' => round((float) $l->a_favor, 2)])->values(),
            'locatarios' => $locatarios->pluck('nombre'),
            'total' => round((float) $locatarios->sum('a_favor'), 2),
            'elegido' => $elegido ? [
                'id' => $elegido->id,
                'nombre' => $elegido->nombre,
                'a_favor' => $elegido->saldoAFavor(),
                'deuda' => $elegido->deuda(),
                'movimientos' => $elegido->creditos()->orderByDesc('fecha')->get(),
                'plan' => array_map(fn ($p) => [
                    'tipo' => $p['tipo'], 'id' => $p['deuda']->id, 'descripcion' => $p['descripcion'],
                    'fecha' => $p['fecha']->toDateString(), 'debe' => $p['debe'], 'monto' => $p['monto'], 'parcial' => $p['parcial'],
                ], $plan),
                'whatsapp' => $elegido->saldoAFavor() > 0 ? WhatsApp::link($elegido->whatsapp,
                    "Hola {$elegido->nombre}\nTe aviso que tienes un saldo a favor de *".dinero($elegido->saldoAFavor())
                    ."* con nosotros.\nSe descontará de tus próximos equipos. ¡Gracias!") : null,
            ] : null,
        ]);
    }

    /**
     * Reparte el saldo a favor entre todo lo que debe (teléfonos y ventas a crédito), del más antiguo al más nuevo:
     * liquida los que alcance y al último le abona lo que sobre.
     */
    protected function plan(Locatario $l): array
    {
        $deudas = $l->telefonos()->conSaldo()->get()->map(fn (Telefono $t) => [
            'tipo' => 'telefono', 'deuda' => $t, 'fecha' => $t->fecha_recibido, 'debe' => $t->saldo,
            'descripcion' => "Teléfono #{$t->id} {$t->modelo}".($t->trabajo ? " ({$t->trabajo})" : ''),
        ])->concat($l->ventas()->conSaldo()->with('detalles')->get()->map(fn (Venta $v) => [
            'tipo' => 'venta', 'deuda' => $v, 'fecha' => $v->created_at, 'debe' => $v->saldo,
            'descripcion' => "Venta N° {$v->folio}: ".$v->detalles->pluck('nombre')->take(3)->implode(', '),
        ]))
            ->filter(fn ($d) => $d['debe'] > 0)
            ->sortBy([fn ($a, $b) => $a['fecha'] <=> $b['fecha'], fn ($a, $b) => $a['deuda']->id <=> $b['deuda']->id])
            ->values();

        $disponible = $l->saldoAFavor();
        $plan = [];
        foreach ($deudas as $d) {
            if ($disponible <= 0) {
                break;
            }
            $usar = round(min($disponible, $d['debe']), 2);
            $plan[] = $d + ['monto' => $usar, 'parcial' => $usar < $d['debe']];
            $disponible = round($disponible - $usar, 2);
        }

        return $plan;
    }

    public function deposito(Request $r)
    {
        $d = $r->validate([
            'locatario' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'nota' => ['nullable', 'string', 'max:200'],
        ]);
        $loc = DB::transaction(function () use ($d) {
            $loc = Locatario::porNombre($d['locatario']);
            $pago = Pago::create([
                'fecha' => now(), 'telefono_id' => null, 'locatario_id' => $loc->id,
                'descripcion' => $loc->nombre.' - (saldo a favor)', 'concepto' => 'Depósito a saldo a favor',
                'monto' => $d['monto'], 'user_id' => auth()->id(),
            ]);
            Credito::create([
                'fecha' => now(), 'locatario_id' => $loc->id, 'tipo' => Credito::DEPOSITO,
                'concepto' => empty($d['nota']) ? 'Depósito' : 'Depósito: '.$d['nota'],
                'monto' => $d['monto'], 'pago_id' => $pago->id, 'user_id' => auth()->id(),
            ]);

            return $loc;
        });

        return response()->json(['message' => 'Depósito de '.dinero($d['monto'])." a {$loc->nombre} (entró a la caja de hoy).", 'locatario_id' => $loc->id], 201);
    }

    public function aplicar(Locatario $locatario)
    {
        $plan = $this->plan($locatario);
        if (! $plan) {
            return response()->json(['message' => "{$locatario->nombre} no tiene saldo a favor o no debe nada."], 422);
        }
        DB::transaction(function () use ($plan, $locatario) {
            foreach ($plan as $p) {
                // sin cobro en caja: el dinero ya entró cuando se depositó el saldo a favor
                $deuda = $p['deuda'];
                $deuda->pagado = round((float) $deuda->pagado + $p['monto'], 2);
                if ($p['tipo'] === 'venta' && $deuda->saldo <= 0) {
                    $deuda->estatus = Venta::PAGADA;
                }
                $deuda->save();
                Credito::create([
                    'fecha' => now(), 'locatario_id' => $locatario->id, 'tipo' => Credito::APLICADO,
                    'concepto' => 'Aplicado a '.($p['tipo'] === 'venta' ? "venta N° {$deuda->folio}" : "#{$deuda->id} {$deuda->modelo}")
                        .($p['parcial'] ? ' (abono)' : ''),
                    'monto' => -$p['monto'],
                    'telefono_id' => $p['tipo'] === 'telefono' ? $deuda->id : null,
                    'venta_id' => $p['tipo'] === 'venta' ? $deuda->id : null,
                    'user_id' => auth()->id(),
                ]);
            }
        });
        $suma = array_sum(array_column($plan, 'monto'));
        $liquidados = count(array_filter($plan, fn ($p) => ! $p['parcial']));
        $abono = collect($plan)->firstWhere('parcial', true);

        $partes = array_filter([
            $liquidados ? ($liquidados === 1 ? '1 adeudo liquidado' : "{$liquidados} adeudos liquidados") : null,
            $abono ? 'abono de '.dinero($abono['monto']).' a '.$abono['descripcion'] : null,
        ]);

        return response()->json(['message' => 'Se usaron '.dinero($suma).' del saldo a favor: '.implode(' y ', $partes).'.']);
    }

    public function devolver(Request $r, Locatario $locatario)
    {
        $saldo = $locatario->saldoAFavor();
        $monto = (float) $r->validate(['monto' => ['required', 'numeric', 'gt:0', 'max:'.$saldo]],
            ['monto.max' => 'No puedes devolver más de lo que tiene a favor ('.dinero($saldo).').'])['monto'];
        DB::transaction(function () use ($locatario, $monto) {
            $salida = Salida::create([
                'fecha' => now(), 'concepto' => 'Devolución de saldo a favor - '.$locatario->nombre,
                'categoria' => 'Devoluciones', 'monto' => $monto, 'notas' => '', 'user_id' => auth()->id(),
            ]);
            Credito::create([
                'fecha' => now(), 'locatario_id' => $locatario->id, 'tipo' => Credito::DEVUELTO,
                'concepto' => 'Devolución en efectivo', 'monto' => -$monto, 'salida_id' => $salida->id, 'user_id' => auth()->id(),
            ]);
        });

        return response()->json(['message' => 'Devolución de '.dinero($monto).' registrada como salida de caja.']);
    }

    public function eliminar(Credito $credito)
    {
        $loc = $credito->locatario;
        if ($credito->tipo === Credito::DEPOSITO && $loc->saldoAFavor() - (float) $credito->monto < -0.001) {
            return response()->json(['message' => 'No se puede eliminar este depósito porque ya se usó. Primero elimina los movimientos APLICADO o DEVUELTO que lo usaron.'], 422);
        }
        DB::transaction(function () use ($credito) {
            if ($credito->tipo === Credito::DEPOSITO && $credito->pago_id) {
                Pago::whereKey($credito->pago_id)->delete();
            } elseif ($credito->tipo === Credito::DEVUELTO && $credito->salida_id) {
                Salida::whereKey($credito->salida_id)->delete();
            } elseif ($credito->tipo === Credito::APLICADO && $credito->telefono) {
                $t = $credito->telefono;
                $t->update(['pagado' => max(0, round((float) $t->pagado + (float) $credito->monto, 2))]);
            } elseif ($credito->tipo === Credito::APLICADO && $credito->venta) {
                // la venta vuelve a deber lo que se le había aplicado
                $v = $credito->venta;
                $v->pagado = max(0, round((float) $v->pagado + (float) $credito->monto, 2));
                if ($v->estatus === Venta::PAGADA && $v->saldo > 0) {
                    $v->estatus = Venta::PENDIENTE;
                }
                $v->save();
            }
            $credito->delete();
        });

        return response()->json(['message' => 'Movimiento eliminado.']);
    }
}

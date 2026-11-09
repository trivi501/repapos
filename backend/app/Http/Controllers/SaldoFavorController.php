<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Locatario;
use App\Models\Pago;
use App\Models\Salida;
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
                    'id' => $p['telefono']->id, 'modelo' => $p['telefono']->modelo, 'monto' => $p['monto'], 'parcial' => $p['parcial'],
                ], $plan),
                'whatsapp' => $elegido->saldoAFavor() > 0 ? WhatsApp::link($elegido->whatsapp,
                    "Hola {$elegido->nombre}\nTe aviso que tienes un saldo a favor de *".dinero($elegido->saldoAFavor())
                    ."* con nosotros.\nSe descontará de tus próximos equipos. ¡Gracias!") : null,
            ] : null,
        ]);
    }

    /** Reparte el saldo a favor entre sus teléfonos que deben: primero los entregados, luego por fecha. */
    protected function plan(Locatario $l): array
    {
        $disponible = $l->saldoAFavor();
        $plan = [];
        $deben = $l->telefonos()->conSaldo()->orderByDesc('entregado')->orderBy('fecha_recibido')->orderBy('id')->get();
        foreach ($deben as $t) {
            if ($disponible <= 0) {
                break;
            }
            $usar = round(min($disponible, $t->saldo), 2);
            $plan[] = ['telefono' => $t, 'monto' => $usar, 'parcial' => $usar < $t->saldo];
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
                $t = $p['telefono'];
                $t->update(['pagado' => round((float) $t->pagado + $p['monto'], 2)]); // sin cobro en caja: ya entró como depósito
                Credito::create([
                    'fecha' => now(), 'locatario_id' => $locatario->id, 'tipo' => Credito::APLICADO,
                    'concepto' => "Aplicado a #{$t->id} {$t->modelo}", 'monto' => -$p['monto'],
                    'telefono_id' => $t->id, 'user_id' => auth()->id(),
                ]);
            }
        });
        $suma = array_sum(array_column($plan, 'monto'));

        return response()->json(['message' => 'Se usaron '.dinero($suma).' del saldo a favor en '.count($plan).' teléfono(s).']);
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
            }
            $credito->delete();
        });

        return response()->json(['message' => 'Movimiento eliminado.']);
    }
}

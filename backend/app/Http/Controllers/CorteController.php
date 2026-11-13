<?php

namespace App\Http\Controllers;

use App\Models\Corte;
use App\Models\OrdenReparacion;
use App\Models\Pago;
use App\Models\Salida;
use App\Models\Telefono;
use App\Models\User;
use App\Models\Venta;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Corte de caja por turno: un día puede tener varios turnos y cada uno cubre de "desde" a "hasta".
 * Solo el efectivo está en la caja; tarjeta y transferencia se muestran aparte.
 */
class CorteController extends Controller
{
    /** De dónde viene cada cobro. */
    private const AREAS = [
        'reparaciones' => 'Reparaciones',
        'telefonos' => 'Teléfonos (locatarios)',
        'ventas' => 'Ventas de mostrador',
        'saldos' => 'Saldos a favor',
        'otros' => 'Otros',
    ];

    public function index(Request $r)
    {
        $dia = $r->filled('fecha') ? Carbon::parse($r->input('fecha'))->startOfDay() : today();
        $finDia = $dia->copy()->addDay();
        $turnos = Corte::with('user:id,name')->whereDate('fecha', $dia)->orderBy('turno')->get();
        $ultimo = $turnos->last();
        // queda un turno abierto si el último corte no llegó al fin del día
        $hayAbierto = ! $ultimo || $ultimo->fin()->lt($finDia);

        $corte = $r->filled('turno') ? $turnos->firstWhere('turno', $r->integer('turno')) : null;
        if (! $corte && ! $hayAbierto) {
            $corte = $ultimo;   // el día ya se cerró: se muestra el último turno
        }

        if ($corte) {
            [$turno, $desde, $hasta] = [$corte->turno, $corte->inicio(), $corte->fin()];
            $fondo = (float) $corte->fondo;
        } else {
            [$turno, $desde, $hasta] = [($ultimo?->turno ?? 0) + 1, $ultimo?->fin() ?? $dia->copy(), $finDia];
            $fondo = $this->fondoAnterior($dia, $ultimo);
        }

        $periodo = $this->periodo($desde, $hasta);
        $cambiosDespues = $corte && (round((float) $corte->entradas, 2) != $periodo['entradas']
            || round((float) $corte->salidas, 2) != $periodo['total_salidas']);

        return response()->json($periodo + [
            'dia' => $dia->toDateString(),
            'turno' => $turno,
            'abierto' => ! $corte,
            'hay_abierto' => $hayAbierto,
            'desde' => $desde->toIso8601String(),
            'hasta' => $hasta->toIso8601String(),
            'turnos' => $turnos->map(fn (Corte $c) => [
                'turno' => $c->turno, 'desde' => $c->inicio()->toIso8601String(), 'hasta' => $c->fin()->toIso8601String(),
                'contado' => (float) $c->contado, 'diferencia' => (float) $c->diferencia, 'retiro' => (float) $c->retiro,
                'user' => $c->user?->name,
            ])->values(),
            'corte' => $corte,
            'fondo' => $fondo,
            'cambios_despues' => $cambiosDespues,
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'fecha' => ['required', 'date'],
            'turno' => ['nullable', 'integer', 'min:1'],
            'fondo' => ['required', 'numeric', 'min:0'],
            'contado' => ['required', 'numeric', 'min:0'],
            'retiro' => ['nullable', 'numeric', 'min:0', 'lte:contado'],
            'notas' => ['nullable', 'string', 'max:255'],
        ], ['retiro.lte' => 'No puedes retirar más de lo que contaste.']);

        $dia = Carbon::parse($d['fecha'])->startOfDay();
        $finDia = $dia->copy()->addDay();
        $turnos = Corte::whereDate('fecha', $dia)->orderBy('turno')->get();
        $ultimo = $turnos->last();

        // sin turno se reemplaza el último del día (o se crea el primero)
        $num = $d['turno'] ?? ($ultimo?->turno ?? 1);
        $corte = $turnos->firstWhere('turno', $num);

        // la base guarda la hora sin fracciones de segundo: el turno cierra al segundo siguiente
        // para que entre lo que se cobró en el mismo segundo del corte
        $ahora = now()->startOfSecond()->addSecond();
        if ($corte) {
            $desde = $corte->inicio();
            // al volver a guardar el último turno de hoy, también entra lo que se cobró después
            $hasta = $corte->is($ultimo) && $dia->isToday() ? $ahora : $corte->fin();
        } else {
            if ($num !== ($ultimo?->turno ?? 0) + 1) {
                return response()->json(['message' => 'Ese turno no existe. Cierra los turnos en orden.'], 422);
            }
            $desde = $ultimo?->fin() ?? $dia->copy();
            $hasta = $dia->isToday() ? $ahora : $finDia;
            if ($desde->gte($hasta)) {
                return response()->json(['message' => 'Este día ya está cerrado: no hay movimientos después del último corte.'], 422);
            }
            $corte = new Corte(['fecha' => $dia, 'turno' => $num]);
        }

        $entradas = $this->efectivo($desde, $hasta);
        $salidas = round((float) $this->salidasEntre($desde, $hasta)->sum('monto'), 2);
        $esperado = round($d['fondo'] + $entradas - $salidas, 2);
        // si no dicen cuánto se retira, se deja el mismo fondo y se retira lo demás
        $retiro = round((float) ($d['retiro'] ?? max(0, $d['contado'] - $d['fondo'])), 2);

        $corte->fill([
            'desde' => $desde, 'hasta' => $hasta,
            'fondo' => $d['fondo'], 'entradas' => $entradas, 'salidas' => $salidas, 'esperado' => $esperado,
            'contado' => $d['contado'], 'diferencia' => round($d['contado'] - $esperado, 2),
            'retiro' => $retiro, 'fondo_siguiente' => round($d['contado'] - $retiro, 2),
            'notas' => trim($d['notas'] ?? ''), 'user_id' => auth()->id(),
        ])->save();

        return response()->json(['message' => "Corte del turno {$corte->turno} guardado.", 'corte' => $corte]);
    }

    public function historial()
    {
        return response()->json(Corte::with('user:id,name')->orderByDesc('fecha')->orderByDesc('turno')->paginate(60));
    }

    /** Totales por semana o por mes: cobros por forma de pago, salidas, faltantes, sobrantes y retiros. */
    public function resumen(Request $r)
    {
        [$desde, $hasta, $agrupar] = $this->rango($r);

        return response()->json([
            'agrupar' => $agrupar,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->copy()->subDay()->toDateString(),
            'filas' => $this->filasResumen($desde, $hasta, $agrupar),
        ]);
    }

    /** Excel (CSV) con el resumen por periodo y el detalle de cada corte. */
    public function exportar(Request $r): StreamedResponse
    {
        [$desde, $hasta, $agrupar] = $this->rango($r);
        $filas = $this->filasResumen($desde, $hasta, $agrupar);
        $cortes = Corte::with('user:id,name')->where('fecha', '>=', $desde)->where('fecha', '<', $hasta)
            ->orderBy('fecha')->orderBy('turno')->get();

        return response()->streamDownload(function () use ($filas, $cortes, $agrupar) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // para que Excel lea bien los acentos
            fputcsv($out, ['Resumen por '.($agrupar === 'mes' ? 'mes' : 'semana')]);
            fputcsv($out, ['Periodo', 'Del', 'Al', 'Efectivo', 'Tarjeta', 'Transferencia', 'Total cobrado', 'Salidas',
                'Cortes', 'Faltantes', 'Sobrantes', 'Retiros']);
            foreach ($filas as $f) {
                fputcsv($out, [$f['etiqueta'], $f['desde'], $f['hasta'], $f['efectivo'], $f['tarjeta'], $f['transferencia'],
                    $f['total'], $f['salidas'], $f['cortes'], $f['faltantes'], $f['sobrantes'], $f['retiros']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Detalle de cortes']);
            fputcsv($out, ['Fecha', 'Turno', 'Desde', 'Hasta', 'Fondo', 'Entradas efectivo', 'Salidas', 'Debía haber',
                'Contado', 'Diferencia', 'Retiro', 'Fondo que dejó', 'Notas', 'Hizo el corte']);
            foreach ($cortes as $c) {
                fputcsv($out, [$c->fecha->format('d/m/Y'), $c->turno, $c->inicio()->format('H:i'), $c->fin()->format('H:i'),
                    $c->fondo, $c->entradas, $c->salidas, $c->esperado, $c->contado, $c->diferencia, $c->retiro,
                    number_format($c->fondoQueDeja(), 2, '.', ''), $c->notas, $c->user?->name]);
            }
            fclose($out);
        }, 'cortes_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ cálculos

    /** Todo lo que pasó en la caja entre dos momentos. */
    private function periodo(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $pagos = Pago::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)->orderBy('fecha')->get();
        $salidas = $this->salidasEntre($desde, $hasta);
        $nombres = User::whereIn('id', $pagos->pluck('user_id')->merge($salidas->pluck('user_id'))->filter()->unique())->pluck('name', 'id');

        $datos = [
            'pagos' => $pagos,
            'salidas' => $salidas,
            'entradas' => round((float) $pagos->where('metodo', Venta::EFECTIVO)->sum('monto'), 2),
            'cobros_efectivo' => $pagos->where('metodo', Venta::EFECTIVO)->count(),
            'otros_metodos' => $pagos->where('metodo', '!=', Venta::EFECTIVO)->groupBy('metodo')->map(fn ($g) => round((float) $g->sum('monto'), 2)),
            'total_salidas' => round((float) $salidas->sum('monto'), 2),
            'desglose' => $this->desglose($pagos),
            'por_empleado' => $this->porEmpleado($pagos, $salidas, $nombres),
        ];
        // la ganancia solo la ve el dueño (el mismo permiso que los costos de las reparaciones)
        if (auth()->user()?->can('ordenes.ver_costos')) {
            $datos['ganancia'] = $this->ganancia($desde, $hasta);
        }

        return $datos;
    }

    private function area(Pago $p): string
    {
        return match (true) {
            $p->orden_id !== null => 'reparaciones',
            $p->telefono_id !== null => 'telefonos',
            $p->venta_id !== null => 'ventas',
            $p->locatario_id !== null => 'saldos',
            default => 'otros',
        };
    }

    /** Cobros por área y forma de pago. */
    private function desglose(Collection $pagos): array
    {
        $filas = [];
        foreach ($pagos as $p) {
            $area = $this->area($p);
            $metodo = in_array($p->metodo, Venta::METODOS, true) ? $p->metodo : Venta::EFECTIVO;
            $filas[$area] ??= ['area' => $area, 'texto' => self::AREAS[$area], 'cobros' => 0,
                Venta::EFECTIVO => 0.0, Venta::TARJETA => 0.0, Venta::TRANSFERENCIA => 0.0, 'total' => 0.0];
            $filas[$area]['cobros']++;
            $filas[$area][$metodo] = round($filas[$area][$metodo] + (float) $p->monto, 2);
            $filas[$area]['total'] = round($filas[$area]['total'] + (float) $p->monto, 2);
        }

        // en el orden de AREAS
        return array_values(array_filter(array_map(fn ($a) => $filas[$a] ?? null, array_keys(self::AREAS))));
    }

    /** Cuánto cobró (y sacó) cada empleado. */
    private function porEmpleado(Collection $pagos, Collection $salidas, Collection $nombres): array
    {
        return $pagos->pluck('user_id')->merge($salidas->pluck('user_id'))->unique()->values()
            ->map(function ($id) use ($pagos, $salidas, $nombres) {
                $suyos = $pagos->where('user_id', $id);

                return [
                    'nombre' => $id ? ($nombres[$id] ?? 'Usuario eliminado') : 'Sin usuario',
                    'cobros' => $suyos->count(),
                    'efectivo' => round((float) $suyos->where('metodo', Venta::EFECTIVO)->sum('monto'), 2),
                    'otros' => round((float) $suyos->where('metodo', '!=', Venta::EFECTIVO)->sum('monto'), 2),
                    'salidas' => round((float) $salidas->where('user_id', $id)->sum('monto'), 2),
                ];
            })
            ->sortByDesc(fn ($e) => $e['efectivo'] + $e['otros'])->values()->all();
    }

    /** Ganancia de las reparaciones terminadas, las ventas y los teléfonos entregados del periodo. */
    private function ganancia(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $ordenes = OrdenReparacion::where('terminado_at', '>=', $desde)->where('terminado_at', '<', $hasta)->get();
        $refaccion = round((float) $ordenes->sum('costo_refaccion'), 2);
        $manoObra = round((float) $ordenes->sum('costo_mano_obra'), 2);
        $gananciaOrdenes = round($ordenes->sum(fn (OrdenReparacion $o) => $o->ganancia), 2);

        $ventas = Venta::with('detalles')->where('created_at', '>=', $desde)->where('created_at', '<', $hasta)
            ->where('estatus', '!=', Venta::CANCELADA)->get();
        $netoVentas = round($ventas->sum(fn (Venta $v) => $v->neto), 2);
        $gananciaVentas = round($ventas->sum(fn (Venta $v) => $v->ganancia), 2);

        // teléfonos de locatarios: cuentan cuando se entregan (no tienen fecha de "listo")
        $telefonos = Telefono::where('entregado', true)->where('fecha_entrega', '>=', $desde)->where('fecha_entrega', '<', $hasta)->get();
        $gastoTelefonos = round((float) $telefonos->sum('costo'), 2);
        $gananciaTelefonos = round($telefonos->sum(fn (Telefono $t) => $t->ganancia), 2);

        return [
            'reparaciones' => [
                'cantidad' => $ordenes->count(),
                'cobrado' => round($gananciaOrdenes + $refaccion + $manoObra, 2),
                'refaccion' => $refaccion,
                'mano_obra' => $manoObra,
                'ganancia' => $gananciaOrdenes,
                // sin costos capturados la ganancia sale igual a lo cobrado: hay que avisar
                'sin_costos' => $ordenes->filter(fn ($o) => $o->costo_refaccion === null && $o->costo_mano_obra === null)->count(),
            ],
            'ventas' => [
                'cantidad' => $ventas->count(),
                'vendido' => $netoVentas,
                'costo' => round($netoVentas - $gananciaVentas, 2),
                'ganancia' => $gananciaVentas,
            ],
            'telefonos' => [
                'cantidad' => $telefonos->count(),
                'cobrado' => round($gananciaTelefonos + $gastoTelefonos, 2),
                'gasto' => $gastoTelefonos,
                'ganancia' => $gananciaTelefonos,
                'sin_gasto' => $telefonos->whereNull('costo')->count(),
            ],
            'total' => round($gananciaOrdenes + $gananciaVentas + $gananciaTelefonos, 2),
        ];
    }

    private function efectivo(CarbonInterface $desde, CarbonInterface $hasta): float
    {
        return round((float) Pago::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)
            ->where('metodo', Venta::EFECTIVO)->sum('monto'), 2);
    }

    private function salidasEntre(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Salida::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)->orderBy('fecha')->get();
    }

    /** El fondo con que empieza el turno: lo que dejó el turno anterior (de este día o del último día con corte). */
    private function fondoAnterior(CarbonInterface $dia, ?Corte $ultimoDelDia): float
    {
        if ($ultimoDelDia) {
            return $ultimoDelDia->fondoQueDeja();
        }

        return Corte::whereDate('fecha', '<', $dia)->orderByDesc('fecha')->orderByDesc('turno')->first()?->fondoQueDeja() ?? 0.0;
    }

    /** Rango del resumen: por semana (12 semanas) o por mes (12 meses) si no se elige otro. */
    private function rango(Request $r): array
    {
        $agrupar = $r->input('agrupar') === 'mes' ? 'mes' : 'semana';
        $hasta = $r->filled('hasta') ? Carbon::parse($r->input('hasta'))->startOfDay()->addDay() : today()->addDay();
        $desde = $r->filled('desde') ? Carbon::parse($r->input('desde'))->startOfDay()
            : ($agrupar === 'mes' ? today()->subMonths(11)->startOfMonth() : today()->subWeeks(11)->startOfWeek());

        return [$desde, $hasta, $agrupar];
    }

    private function filasResumen(CarbonInterface $desde, CarbonInterface $hasta, string $agrupar): array
    {
        $clave = fn (CarbonInterface $f) => $agrupar === 'mes' ? $f->format('Y-m') : $f->copy()->startOfWeek()->toDateString();
        $pagos = Pago::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)->get(['fecha', 'monto', 'metodo']);
        $salidas = Salida::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)->get(['fecha', 'monto']);
        $cortes = Corte::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)->get();

        $filas = [];
        $fila = function (CarbonInterface $f) use (&$filas, $clave, $agrupar) {
            $k = $clave($f);
            if (! isset($filas[$k])) {
                $inicio = $agrupar === 'mes' ? $f->copy()->startOfMonth() : $f->copy()->startOfWeek();
                $fin = $agrupar === 'mes' ? $f->copy()->endOfMonth() : $f->copy()->endOfWeek();
                $filas[$k] = [
                    'clave' => $k,
                    'etiqueta' => $agrupar === 'mes' ? ucfirst($inicio->locale('es')->translatedFormat('F Y'))
                        : 'Semana del '.$inicio->format('d/m').' al '.$fin->format('d/m/Y'),
                    'desde' => $inicio->toDateString(), 'hasta' => $fin->toDateString(),
                    'efectivo' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0, 'total' => 0.0, 'salidas' => 0.0,
                    'cortes' => 0, 'faltantes' => 0.0, 'sobrantes' => 0.0, 'retiros' => 0.0,
                ];
            }

            return $k;
        };

        foreach ($pagos as $p) {
            $k = $fila($p->fecha instanceof CarbonInterface ? $p->fecha : Carbon::parse($p->fecha));
            $m = match ($p->metodo) { Venta::TARJETA => 'tarjeta', Venta::TRANSFERENCIA => 'transferencia', default => 'efectivo' };
            $filas[$k][$m] += (float) $p->monto;
            $filas[$k]['total'] += (float) $p->monto;
        }
        foreach ($salidas as $s) {
            $k = $fila($s->fecha instanceof CarbonInterface ? $s->fecha : Carbon::parse($s->fecha));
            $filas[$k]['salidas'] += (float) $s->monto;
        }
        foreach ($cortes as $c) {
            $k = $fila($c->fecha);
            $dif = (float) $c->diferencia;
            $filas[$k]['cortes']++;
            $filas[$k]['faltantes'] += $dif < 0 ? -$dif : 0;
            $filas[$k]['sobrantes'] += $dif > 0 ? $dif : 0;
            $filas[$k]['retiros'] += (float) $c->retiro;
        }

        krsort($filas);

        return array_values(array_map(function ($f) {
            foreach (['efectivo', 'tarjeta', 'transferencia', 'total', 'salidas', 'faltantes', 'sobrantes', 'retiros'] as $campo) {
                $f[$campo] = round($f[$campo], 2);
            }

            return $f;
        }, $filas));
    }
}

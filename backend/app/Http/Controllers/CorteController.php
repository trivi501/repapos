<?php

namespace App\Http\Controllers;

use App\Models\Corte;
use App\Models\Pago;
use App\Models\Salida;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CorteController extends Controller
{
    public function index(Request $r)
    {
        $dia = $r->filled('fecha') ? Carbon::parse($r->input('fecha'))->startOfDay() : today();
        $pagos = Pago::where('fecha', '>=', $dia)->where('fecha', '<', $dia->copy()->addDay())->orderBy('fecha')->get();
        $salidas = Salida::where('fecha', '>=', $dia)->where('fecha', '<', $dia->copy()->addDay())->orderBy('fecha')->get();
        // solo el efectivo está en la caja; tarjeta y transferencia se muestran aparte
        $entradas = round((float) $pagos->where('metodo', 'EFECTIVO')->sum('monto'), 2);
        $otrosMetodos = $pagos->where('metodo', '!=', 'EFECTIVO')->groupBy('metodo')->map(fn ($g) => round((float) $g->sum('monto'), 2));
        $totalSalidas = round((float) $salidas->sum('monto'), 2);
        $corte = Corte::with('user:id,name')->whereDate('fecha', $dia)->first();
        // el fondo suele ser el mismo de un día a otro
        $fondo = $corte ? (float) $corte->fondo
            : (float) (Corte::whereDate('fecha', '<', $dia)->orderByDesc('fecha')->value('fondo') ?? 0);
        $cambiosDespues = $corte && (round((float) $corte->entradas, 2) != $entradas || round((float) $corte->salidas, 2) != $totalSalidas);

        return response()->json([
            'dia' => $dia->toDateString(),
            'pagos' => $pagos,
            'salidas' => $salidas,
            'entradas' => $entradas,
            'cobros_efectivo' => $pagos->where('metodo', 'EFECTIVO')->count(),
            'otros_metodos' => $otrosMetodos,
            'total_salidas' => $totalSalidas,
            'corte' => $corte,
            'fondo' => $fondo,
            'cambios_despues' => $cambiosDespues,
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'fecha' => ['required', 'date'],
            'fondo' => ['required', 'numeric', 'min:0'],
            'contado' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);
        $dia = Carbon::parse($d['fecha'])->startOfDay();
        $entradas = round((float) Pago::where('fecha', '>=', $dia)->where('fecha', '<', $dia->copy()->addDay())->where('metodo', 'EFECTIVO')->sum('monto'), 2);
        $salidas = round((float) Salida::where('fecha', '>=', $dia)->where('fecha', '<', $dia->copy()->addDay())->sum('monto'), 2);
        $esperado = round($d['fondo'] + $entradas - $salidas, 2);

        $corte = Corte::whereDate('fecha', $dia)->first() ?? new Corte(['fecha' => $dia]);
        $corte->fill([
            'fondo' => $d['fondo'], 'entradas' => $entradas, 'salidas' => $salidas, 'esperado' => $esperado,
            'contado' => $d['contado'], 'diferencia' => round($d['contado'] - $esperado, 2),
            'notas' => trim($d['notas'] ?? ''), 'user_id' => auth()->id(),
        ])->save();

        return response()->json(['message' => 'Corte guardado.', 'corte' => $corte]);
    }

    public function historial()
    {
        return response()->json(Corte::with('user:id,name')->orderByDesc('fecha')->paginate(60));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Salida;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SalidaController extends Controller
{
    public function index(Request $r)
    {
        $desde = $r->filled('desde') ? Carbon::parse($r->input('desde'))->startOfDay() : today();
        $hasta = $r->filled('hasta') ? Carbon::parse($r->input('hasta'))->startOfDay() : today();
        if ($hasta->lt($desde)) {
            $hasta = $desde->copy();
        }

        return response()->json([
            'salidas' => Salida::where('fecha', '>=', $desde)->where('fecha', '<', $hasta->copy()->addDay())->orderByDesc('fecha')->get(),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'categorias' => Salida::CATEGORIAS,
        ]);
    }

    protected function validar(Request $r): array
    {
        return $r->validate([
            'concepto' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function campos(array $d): array
    {
        return [
            // el formulario manda la hora local del taller (datetime-local)
            'fecha' => Carbon::parse($d['fecha'], config('app.timezone')), 'concepto' => trim($d['concepto']),
            'categoria' => trim($d['categoria'] ?? ''), 'monto' => $d['monto'], 'notas' => trim($d['notas'] ?? ''),
        ];
    }

    public function store(Request $r)
    {
        $s = Salida::create($this->campos($this->validar($r)) + ['user_id' => auth()->id()]);

        return response()->json(['message' => "Salida guardada: {$s->concepto} ".dinero($s->monto), 'salida' => $s], 201);
    }

    public function update(Request $r, Salida $salida)
    {
        $salida->update($this->campos($this->validar($r)));

        return response()->json(['message' => 'Cambios guardados.', 'salida' => $salida]);
    }

    public function destroy(Salida $salida)
    {
        $salida->delete(); // si era una devolución de saldo a favor, el crédito queda sin salida ligada

        return response()->json(null, 204);
    }
}

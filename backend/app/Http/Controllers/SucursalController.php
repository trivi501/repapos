<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SucursalController extends Controller
{
    public function index()
    {
        return response()->json(Sucursal::orderBy('id')->get());
    }

    protected function validar(Request $r): array
    {
        return $r->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function campos(array $d): array
    {
        return ['nombre' => trim($d['nombre']), 'direccion' => trim($d['direccion'] ?? ''), 'telefono' => trim($d['telefono'] ?? '')];
    }

    public function store(Request $r)
    {
        $s = Sucursal::create($this->campos($this->validar($r)));

        return response()->json(['message' => "Sucursal {$s->nombre} creada.", 'sucursal' => $s], 201);
    }

    public function update(Request $r, Sucursal $sucursal)
    {
        $sucursal->update($this->campos($this->validar($r)));

        return response()->json(['message' => 'Sucursal actualizada.', 'sucursal' => $sucursal]);
    }

    public function destroy(Sucursal $sucursal)
    {
        if (Sucursal::count() <= 1) {
            return response()->json(['message' => 'El taller debe tener al menos una sucursal.'], 422);
        }
        foreach (['telefonos', 'ordenes_reparacion', 'pagos', 'salidas', 'cortes', 'movimientos', 'ventas'] as $t) {
            if (DB::table($t)->where('sucursal_id', $sucursal->id)->exists()) {
                return response()->json(['message' => "No se puede borrar {$sucursal->nombre}: ya tiene movimientos. Puedes cambiarle el nombre."], 422);
            }
        }
        $sucursal->delete();

        return response()->json(null, 204);
    }
}

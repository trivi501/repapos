<?php

namespace App\Http\Controllers;

use App\Models\OrdenRefaccion;
use App\Models\OrdenReparacion;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Support\Inventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Refacciones de una reparación.
 * - Del inventario: se descuentan las piezas de la sucursal de la orden y se toma el costo del producto.
 * - Comprada fuera: solo nombre, cantidad y lo que costó.
 * El costo de refacción de la orden es la suma de su lista. Al quitar una del inventario, la pieza regresa.
 */
class OrdenRefaccionController extends Controller
{
    /** Productos para elegir (nombre o código), con las piezas que hay. El costo solo lo ve el dueño. */
    public function productos(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $verCostos = $request->user()->can('ordenes.ver_costos');

        $productos = Producto::query()->with('existencia')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', "%{$q}%")->orWhere('codigo', 'like', "%{$q}%")))
            ->orderBy('nombre')->limit(15)->get();

        return response()->json($productos->map(fn (Producto $p) => [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'codigo' => $p->codigo,
            'categoria' => $p->categoria,
            'hay' => (int) ($p->existencia->cantidad ?? 0),
        ] + ($verCostos ? ['costo' => (float) $p->costo] : []))->values());
    }

    public function store(Request $request, OrdenReparacion $orden)
    {
        $tallerId = $request->user()->taller_id;
        $d = $request->validate([
            'producto_id' => ['nullable', Rule::exists('productos', 'id')->where('taller_id', $tallerId)],
            'descripcion' => ['nullable', 'required_without:producto_id', 'string', 'max:255'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ], [
            'descripcion.required_without' => 'Escribe qué refacción es o elige una del inventario.',
        ]);
        // el costo lo decide solo quien puede verlo; los demás usan el del producto (o 0 si se compró fuera)
        $costoManual = $request->user()->can('ordenes.ver_costos') && isset($d['costo_unitario']) ? (float) $d['costo_unitario'] : null;

        try {
            DB::transaction(function () use ($orden, $d, $costoManual) {
                $producto = isset($d['producto_id']) ? Producto::findOrFail($d['producto_id']) : null;
                $movimientoId = null;
                if ($producto) {
                    $movimientoId = Inventario::usarEnReparacion($producto, (int) $d['cantidad'], $orden->sucursal_id ?? Sucursal::actualId(),
                        "Orden N° {$orden->folio}")->id;
                }
                $orden->refacciones()->create([
                    'producto_id' => $producto?->id,
                    'movimiento_id' => $movimientoId,
                    'descripcion' => trim($d['descripcion'] ?? '') !== '' ? trim($d['descripcion']) : $producto->nombre,
                    'cantidad' => (int) $d['cantidad'],
                    'costo_unitario' => $costoManual ?? (float) ($producto?->costo ?? 0),
                    'user_id' => auth()->id(),
                ]);
                $orden->recalcularCostoRefaccion();
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['cantidad' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'message' => 'Refacción agregada'.(isset($d['producto_id']) ? ' y descontada del inventario.' : '.'),
            'orden' => app(OrdenReparacionController::class)->conEnlaces($orden->refresh()),
        ]);
    }

    public function destroy(OrdenReparacion $orden, OrdenRefaccion $refaccion)
    {
        abort_unless($refaccion->orden_reparacion_id === $orden->id, 404);

        $regreso = DB::transaction(function () use ($orden, $refaccion) {
            $regreso = $refaccion->regresarAlInventario("Se quitó de la orden N° {$orden->folio}");
            $refaccion->delete();
            $orden->recalcularCostoRefaccion();

            return $regreso;
        });

        return response()->json([
            'message' => $regreso ? 'Refacción quitada; la pieza regresó al inventario.' : 'Refacción quitada.',
            'orden' => app(OrdenReparacionController::class)->conEnlaces($orden->refresh()),
        ]);
    }
}

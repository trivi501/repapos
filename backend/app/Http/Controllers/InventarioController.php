<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Sucursal;
use App\Support\Inventario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class InventarioController extends Controller
{
    public function index(Request $r)
    {
        $productos = Producto::query()
            ->with('existencia')   // la de la sucursal actual (el modelo Existencia filtra por sucursal)
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'like', '%'.$r->input('q').'%')
                ->orWhere('codigo', 'like', '%'.$r->input('q').'%')))
            ->when($r->filled('categoria'), fn ($q) => $q->where('categoria', $r->input('categoria')))
            ->orderBy('categoria')->orderBy('nombre')
            ->get();
        if ($r->boolean('bajos')) {
            $productos = $productos->filter(fn ($p) => ($p->existencia->cantidad ?? 0) <= $p->minimo);
        }

        return response()->json([
            'productos' => $productos->map(fn ($p) => $p->toArray() + ['hay' => $p->existencia->cantidad ?? 0])->values(),
            'categorias' => Producto::query()->distinct()->orderBy('categoria')->pluck('categoria')->filter()->merge(Producto::CATEGORIAS)->unique()->values(),
            'sucursales' => Sucursal::where('id', '!=', Sucursal::actualId())->orderBy('nombre')->get(['id', 'nombre']),
            'valor' => $productos->sum(fn ($p) => ($p->existencia->cantidad ?? 0) * (float) $p->costo),
            'bajos' => $productos->filter(fn ($p) => ($p->existencia->cantidad ?? 0) <= $p->minimo && $p->minimo > 0)->count(),
        ]);
    }

    public function show(Producto $inventario)
    {
        return response()->json($inventario);
    }

    protected function validar(Request $r): array
    {
        return $r->validate([
            'codigo' => ['nullable', 'string', 'max:60'],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'costo' => ['required', 'numeric', 'min:0'],
            'precio' => ['required', 'numeric', 'min:0'],
            'precio_locatario' => ['nullable', 'numeric', 'min:0'],
            'minimo' => ['required', 'integer', 'min:0'],
            'existencia_inicial' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function campos(array $d): array
    {
        return [
            'codigo' => trim($d['codigo'] ?? ''), 'nombre' => trim($d['nombre']), 'categoria' => trim($d['categoria'] ?? ''),
            'costo' => $d['costo'], 'precio' => $d['precio'], 'precio_locatario' => $d['precio_locatario'] ?? null, 'minimo' => $d['minimo'],
        ];
    }

    public function store(Request $r)
    {
        $d = $this->validar($r);
        $p = Producto::create($this->campos($d));
        if (($d['existencia_inicial'] ?? 0) > 0) {
            Inventario::ajuste($p, (int) $d['existencia_inicial'], 'Existencia inicial');
        }

        return response()->json(['message' => "Producto {$p->nombre} agregado.", 'producto' => $p], 201);
    }

    public function update(Request $r, Producto $inventario)
    {
        $inventario->update($this->campos($this->validar($r)));

        return response()->json(['message' => 'Producto actualizado.', 'producto' => $inventario]);
    }

    public function destroy(Producto $inventario)
    {
        $inventario->delete();

        return response()->json(null, 204);
    }

    /** Entrada, venta, ajuste o traspaso. */
    public function movimiento(Request $r, Producto $producto)
    {
        $d = $r->validate([
            'tipo' => ['required', Rule::in(['entrada', 'venta', 'ajuste', 'traspaso'])],
            'piezas' => ['required', 'integer', $r->input('tipo') === 'ajuste' ? 'min:0' : 'min:1', 'max:100000'],
            'importe' => ['nullable', 'numeric', 'min:0'],
            'destino' => ['nullable', 'integer'],
            'nota' => ['nullable', 'string', 'max:200'],
            'de_caja' => ['nullable', 'boolean'],
        ]);
        $piezas = (int) $d['piezas'];
        $nota = trim($d['nota'] ?? '');
        try {
            switch ($d['tipo']) {
                case 'entrada':
                    $costo = (float) ($d['importe'] ?? $piezas * (float) $producto->costo);
                    Inventario::entrada($producto, $piezas, $costo, $r->boolean('de_caja'), $nota);
                    $msg = "Entrada de {$piezas} x {$producto->nombre}".($r->boolean('de_caja') && $costo > 0 ? ' (salida de caja '.dinero($costo).')' : '').'.';
                    break;
                case 'venta':
                    $total = (float) ($d['importe'] ?? $piezas * (float) $producto->precio);
                    Inventario::venta($producto, $piezas, $total, $nota);
                    $msg = "Venta de {$piezas} x {$producto->nombre} por ".dinero($total).' (entró a la caja).';
                    break;
                case 'ajuste':
                    $m = Inventario::ajuste($producto, $piezas, $nota);
                    $msg = $m ? "Existencia de {$producto->nombre} ajustada a {$piezas}." : 'La existencia ya era esa; no hubo cambios.';
                    break;
                default:
                    $destino = Sucursal::findOrFail($d['destino'] ?? 0); // solo sucursales del mismo taller
                    Inventario::traspaso($producto, $piezas, $destino, $nota);
                    $msg = "Traspaso de {$piezas} x {$producto->nombre} a {$destino->nombre}.";
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $msg]);
    }

    public function kardex(Producto $producto)
    {
        return response()->json([
            'producto' => $producto->load('existencia')->toArray() + ['hay' => $producto->existencia->cantidad ?? 0],
            'movimientos' => $producto->movimientos()->with('user:id,name')->latest('id')->paginate(100),
        ]);
    }
}

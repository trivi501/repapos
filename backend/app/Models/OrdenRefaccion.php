<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Support\Inventario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Refacción usada en una reparación: del inventario (producto_id) o comprada fuera (solo descripción y costo). */
class OrdenRefaccion extends Model
{
    use DelTaller;

    /** El costo es interno: solo lo ve quien tiene "ordenes.ver_costos" (makeVisible en el controlador). */
    public const COSTOS = ['costo_unitario', 'importe'];

    protected $table = 'orden_refacciones';

    protected $fillable = ['orden_reparacion_id', 'producto_id', 'movimiento_id', 'descripcion', 'cantidad', 'costo_unitario', 'user_id'];

    protected $appends = ['importe', 'del_inventario'];

    protected $hidden = ['taller_id', 'movimiento_id', 'user_id', 'costo_unitario', 'importe'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'costo_unitario' => 'decimal:2'];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenReparacion::class, 'orden_reparacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    /** Si salió del inventario, regresa las piezas a la sucursal de donde salieron. Devuelve si regresó algo. */
    public function regresarAlInventario(string $nota): bool
    {
        $movimiento = $this->movimiento_id ? Movimiento::withoutGlobalScopes()->find($this->movimiento_id) : null;
        $producto = $this->producto_id ? Producto::find($this->producto_id) : null;
        if (! $producto || ! $movimiento) {
            return false;
        }
        Inventario::regresarDeReparacion($producto, $this->cantidad, $movimiento->sucursal_id, $nota);

        return true;
    }

    public function getImporteAttribute(): float
    {
        return round($this->cantidad * (float) $this->costo_unitario, 2);
    }

    public function getDelInventarioAttribute(): bool
    {
        return $this->producto_id !== null || $this->movimiento_id !== null;
    }
}

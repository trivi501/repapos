<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea del kárdex: entrada, venta, ajuste o traspaso de un producto en una sucursal. */
class Movimiento extends Model
{
    use DelTaller, DeSucursal;

    public const ENTRADA = 'ENTRADA';
    public const VENTA = 'VENTA';
    public const AJUSTE = 'AJUSTE';
    public const TRASPASO = 'TRASPASO';
    public const CANCELACION = 'CANCELACIÓN';
    public const DEVOLUCION = 'DEVOLUCIÓN';

    protected $fillable = ['producto_id', 'tipo', 'cantidad', 'existencia', 'importe', 'nota', 'pago_id', 'salida_id', 'venta_id', 'user_id'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'existencia' => 'integer', 'importe' => 'decimal:2'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

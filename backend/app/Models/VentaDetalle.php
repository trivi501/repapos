<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renglón de una venta. No lleva taller_id: se llega a él siempre por su venta (que sí está separada por taller). */
class VentaDetalle extends Model
{
    public $timestamps = false;
    protected $fillable = ['producto_id', 'nombre', 'cantidad', 'devuelto', 'precio', 'costo', 'importe'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'devuelto' => 'integer', 'precio' => 'decimal:2', 'costo' => 'decimal:2', 'importe' => 'decimal:2'];
    }

    /** Piezas que todavía se pueden devolver. */
    public function getDevolviblesAttribute(): int
    {
        return $this->cantidad - $this->devuelto;
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}

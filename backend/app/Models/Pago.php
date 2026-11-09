<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    use DelTaller, DeSucursal;

    protected $fillable = ['fecha', 'telefono_id', 'orden_id', 'venta_id', 'locatario_id', 'descripcion', 'concepto', 'monto', 'metodo', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime', 'monto' => 'decimal:2'];
    }

    public function telefono(): BelongsTo
    {
        return $this->belongsTo(Telefono::class);
    }
}

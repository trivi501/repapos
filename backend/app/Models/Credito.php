<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credito extends Model
{
    use DelTaller;

    public const DEPOSITO = 'DEPÓSITO';
    public const APLICADO = 'APLICADO';
    public const DEVUELTO = 'DEVUELTO';

    protected $fillable = ['fecha', 'locatario_id', 'tipo', 'concepto', 'monto', 'telefono_id', 'pago_id', 'salida_id', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime', 'monto' => 'decimal:2'];
    }

    public function locatario(): BelongsTo
    {
        return $this->belongsTo(Locatario::class);
    }

    public function telefono(): BelongsTo
    {
        return $this->belongsTo(Telefono::class);
    }
}

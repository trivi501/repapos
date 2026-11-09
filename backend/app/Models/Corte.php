<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Corte extends Model
{
    use DelTaller, DeSucursal;

    protected $fillable = ['fecha', 'fondo', 'entradas', 'salidas', 'esperado', 'contado', 'diferencia', 'notas', 'user_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fondo' => 'decimal:2', 'entradas' => 'decimal:2', 'salidas' => 'decimal:2',
            'esperado' => 'decimal:2', 'contado' => 'decimal:2', 'diferencia' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

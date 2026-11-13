<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Corte de caja de un turno: de "desde" a "hasta" (un día puede tener varios turnos). */
class Corte extends Model
{
    use DelTaller, DeSucursal;

    protected $fillable = [
        'fecha', 'turno', 'desde', 'hasta', 'fondo', 'entradas', 'salidas', 'esperado', 'contado', 'diferencia',
        'retiro', 'fondo_siguiente', 'notas', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'desde' => 'datetime', 'hasta' => 'datetime',
            'fondo' => 'decimal:2', 'entradas' => 'decimal:2', 'salidas' => 'decimal:2',
            'esperado' => 'decimal:2', 'contado' => 'decimal:2', 'diferencia' => 'decimal:2',
            'retiro' => 'decimal:2', 'fondo_siguiente' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Inicio del turno (los cortes viejos o importados cubren todo el día). */
    public function inicio(): CarbonInterface
    {
        return $this->desde ?? $this->fecha->copy()->startOfDay();
    }

    public function fin(): CarbonInterface
    {
        return $this->hasta ?? $this->fecha->copy()->addDay()->startOfDay();
    }

    /** Lo que queda en la caja para el siguiente turno. */
    public function fondoQueDeja(): float
    {
        return (float) ($this->fondo_siguiente ?? $this->fondo);
    }
}

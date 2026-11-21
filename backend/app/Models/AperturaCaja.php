<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Apertura de caja de una sucursal: fondo contado al abrir; al cerrar se liga al corte del turno. */
class AperturaCaja extends Model
{
    use DelTaller, DeSucursal;

    protected $table = 'aperturas_caja';

    protected $fillable = ['fecha', 'abierta_at', 'fondo', 'notas', 'user_id', 'cerrada_at', 'cerrada_por', 'corte_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'abierta_at' => 'datetime', 'cerrada_at' => 'datetime', 'fondo' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cerro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class);
    }

    public function scopeAbierta(Builder $q): Builder
    {
        return $q->whereNull('cerrada_at');
    }

    /** La caja abierta de la sucursal actual, o null. */
    public static function actual(): ?self
    {
        return static::abierta()->with('user:id,name')->latest('id')->first();
    }
}

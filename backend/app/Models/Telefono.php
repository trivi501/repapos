<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Telefono extends Model
{
    use DelTaller, DeSucursal;

    public const EN_TALLER = 'EN TALLER';
    public const LISTO = 'LISTO';
    public const NO_QUEDO = 'NO QUEDÓ';
    public const RESULTADOS = [self::EN_TALLER, self::LISTO, self::NO_QUEDO];

    public const ALTA = 'ALTA';
    public const MEDIA = 'MEDIA';
    public const BAJA = 'BAJA';
    public const PRIORIDADES = [self::ALTA, self::MEDIA, self::BAJA];

    protected $fillable = [
        'locatario_id', 'prioridad', 'fecha_recibido', 'modelo', 'trabajo', 'notas',
        'total', 'pagado', 'resultado', 'entregado', 'fecha_entrega', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_recibido' => 'date',
            'fecha_entrega' => 'datetime',
            'entregado' => 'boolean',
            'total' => 'decimal:2',
            'pagado' => 'decimal:2',
        ];
    }

    public function locatario(): BelongsTo
    {
        return $this->belongsTo(Locatario::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    /** Lo que debe. Si no quedó, no se cobra: no debe nada. */
    public function getSaldoAttribute(): float
    {
        if ($this->resultado === self::NO_QUEDO) {
            return 0.0;
        }
        return round((float) $this->total - (float) $this->pagado, 2);
    }

    public function getEstadoPagoAttribute(): string
    {
        if ($this->resultado === self::NO_QUEDO) {
            return 'NO SE COBRA';
        }
        return $this->saldo <= 0 ? 'PAGADO' : 'DEBE';
    }

    /** Teléfonos que deben algo (mismo criterio que getSaldoAttribute, pero en SQL). */
    public function scopeConSaldo(Builder $q): Builder
    {
        return $q->where('resultado', '!=', self::NO_QUEDO)->whereColumn('total', '>', 'pagado');
    }

    public function scopeSinSaldo(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('resultado', self::NO_QUEDO)->orWhereColumn('total', '<=', 'pagado'));
    }

    /** Cambia lo pagado y registra la diferencia como cobro en la caja de hoy. */
    public function cambiarPagado(float $nuevo, string $concepto): void
    {
        $diferencia = round($nuevo - (float) $this->pagado, 2);
        $this->pagado = $nuevo;
        $this->save();
        if ($diferencia != 0) {
            $pago = new Pago([
                'fecha' => now(),
                'telefono_id' => $this->id,
                'locatario_id' => $this->locatario_id,
                'descripcion' => $this->locatario->nombre . ' - ' . $this->modelo,
                'concepto' => $diferencia > 0 ? $concepto : 'Corrección (se restó)',
                'monto' => $diferencia,
                'user_id' => auth()->id(),
            ]);
            $pago->taller_id = $this->taller_id;
            $pago->sucursal_id = $this->sucursal_id;
            $pago->save();
        }
    }
}

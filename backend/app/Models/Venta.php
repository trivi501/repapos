<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Venta de mostrador de productos del inventario (al contado o, a locatarios, a crédito). */
class Venta extends Model
{
    use DelTaller, DeSucursal;

    public const EFECTIVO = 'EFECTIVO';
    public const TARJETA = 'TARJETA';
    public const TRANSFERENCIA = 'TRANSFERENCIA';
    public const METODOS = [self::EFECTIVO, self::TARJETA, self::TRANSFERENCIA];   // con los que entra dinero
    public const CREDITO = 'CRÉDITO';                                                // se paga después (solo locatarios)

    public const PAGADA = 'PAGADA';
    public const PENDIENTE = 'CRÉDITO';     // a crédito, todavía debe
    public const CANCELADA = 'CANCELADA';

    public const CLIENTE = 'CLIENTE';       // cliente final (mostrador), precio público
    public const LOCATARIO = 'LOCATARIO';   // locatario, precio de locatario

    protected $fillable = ['tipo', 'locatario_id', 'cliente_nombre', 'cliente_telefono', 'subtotal', 'descuento', 'total', 'pagado', 'devuelto',
        'metodo', 'recibido', 'estatus', 'cancelada_at', 'pago_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'descuento' => 'decimal:2', 'total' => 'decimal:2', 'pagado' => 'decimal:2',
            'devuelto' => 'decimal:2', 'recibido' => 'decimal:2', 'cancelada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Venta $v) {
            $v->folio ??= (int) DB::table('ventas')->where('taller_id', $v->taller_id ?? Taller::actualId())->max('folio') + 1;
            $v->token ??= \Illuminate\Support\Str::random(32);
        });
    }

    public function locatario(): BelongsTo
    {
        return $this->belongsTo(Locatario::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lo que vale la venta después de devoluciones. */
    public function getNetoAttribute(): float
    {
        return round((float) $this->total - (float) $this->devuelto, 2);
    }

    /** Lo que falta por pagar (ventas a crédito). */
    public function getSaldoAttribute(): float
    {
        if ($this->estatus === self::CANCELADA) {
            return 0.0;
        }
        return max(0, round($this->neto - (float) $this->pagado, 2));
    }

    public function scopeConSaldo(Builder $q): Builder
    {
        return $q->where('estatus', self::PENDIENTE);
    }

    public function getCambioAttribute(): float
    {
        return $this->recibido !== null ? max(0, round((float) $this->recibido - (float) $this->total, 2)) : 0.0;
    }

    public function getGananciaAttribute(): float
    {
        return round($this->neto - $this->detalles->sum(fn ($d) => ($d->cantidad - $d->devuelto) * (float) $d->costo), 2);
    }
}

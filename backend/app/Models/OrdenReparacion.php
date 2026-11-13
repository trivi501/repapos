<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Orden de servicio de un cliente final: lo que se recibió, en qué estado y qué falla tiene. */
class OrdenReparacion extends Model
{
    use DelTaller, DeSucursal, HasFactory;

    public const RECIBIDO = 'recibido';
    public const DIAGNOSTICO = 'diagnostico';
    public const REPARACION = 'en_reparacion';
    public const REFACCION = 'esperando_repuesto';
    public const LISTO = 'reparado';
    public const NO_QUEDO = 'no_quedo';
    public const ENTREGADO = 'entregado';
    public const CANCELADO = 'cancelado';
    // solo para liberaciones
    public const EN_PROCESO = 'en_proceso';
    public const RECHAZADO = 'rechazado';

    /** Estatus de una reparación normal. */
    public const ESTADOS_REPARACION = [self::RECIBIDO, self::DIAGNOSTICO, self::REPARACION, self::REFACCION, self::LISTO, self::NO_QUEDO, self::ENTREGADO, self::CANCELADO];
    /** Una liberación (la orden trae bloqueo de operador, FRP, iCloud, MDM o Knox) solo tiene estos 4. */
    public const ESTADOS_LIBERACION = [self::RECIBIDO, self::EN_PROCESO, self::LISTO, self::RECHAZADO];

    public const ESTADOS = [...self::ESTADOS_REPARACION, self::EN_PROCESO, self::RECHAZADO];

    /** Texto para el cliente, en mensajes y documentos. */
    public const ESTADOS_TEXTO = [
        self::RECIBIDO => 'RECIBIDO',
        self::DIAGNOSTICO => 'DIAGNÓSTICO',
        self::REPARACION => 'EN REPARACIÓN',
        self::REFACCION => 'ESPERA REFACCIÓN',
        self::LISTO => 'LISTO',
        self::NO_QUEDO => 'NO QUEDÓ',
        self::ENTREGADO => 'ENTREGADO',
        self::CANCELADO => 'CANCELADO',
        self::EN_PROCESO => 'EN PROCESO',
        self::RECHAZADO => 'RECHAZADO',
    ];

    /** Revisión al recibir el equipo: cada punto se marca Sí o No (igual que la nota de recepción en papel). */
    public const REVISION = [
        'bateria' => 'Batería',
        'chip' => 'Chip',
        'memoria' => 'Memoria',
        'cargador' => 'Cargador',
        'senal' => 'Señal',
        'receptor_voz' => 'Receptor de voz',
        'pantalla' => 'Pantalla',
        'botones' => 'Botones',
        'golpes' => 'Golpes',
        'mojado' => 'Mojado',
    ];

    protected $table = 'ordenes_reparacion';

    protected $fillable = [
        'numero_orden',
        'cliente_id',
        'equipo_id',
        'tecnico_id',
        'sucursal_id',
        'user_id',
        'estado',
        'motivo_rechazo',
        'prioridad',
        'tipo_servicio',
        'bloqueo_tipo',
        'bloqueo_valor',
        'bloqueo_pin',
        'bloqueos',
        'estado_fisico',
        'revision',
        'observaciones',
        'anticipo',
        'metodo_pago',
        'estimado_listo_at',
        'falla_reportada',
        'diagnostico',
        'solucion',
        'costo_estimado',
        'costo_final',
        'costo_refaccion',
        'costo_mano_obra',
        'fecha_recepcion',
        'fecha_entrega_estimada',
        'fecha_entrega_real',
        'notas',
        'notas_cliente',
        'visible_cliente',
        'visibilidad',
    ];

    /** Costos internos: solo los ve quien tiene "ordenes.ver_costos". */
    public const COSTOS_INTERNOS = ['costo_refaccion', 'costo_mano_obra'];

    // el PIN, el patrón y los costos internos solo se exponen al abrir la orden (makeVisible en el controlador)
    protected $hidden = ['bloqueo_valor', 'bloqueo_pin', 'costo_refaccion', 'costo_mano_obra'];

    protected $appends = ['total', 'saldo', 'es_liberacion'];

    protected function casts(): array
    {
        return [
            'bloqueo_valor' => 'encrypted',
            'bloqueo_pin' => 'encrypted',
            'costo_estimado' => 'decimal:2',
            'costo_final' => 'decimal:2',
            'costo_refaccion' => 'decimal:2',
            'costo_mano_obra' => 'decimal:2',
            'anticipo' => 'decimal:2',
            'pagado' => 'decimal:2',
            'estado_fisico' => 'array',
            'revision' => 'array',
            'bloqueos' => 'array',
            'visible_cliente' => 'boolean',
            'visibilidad' => 'array',
            'estimado_listo_at' => 'datetime',
            'fecha_recepcion' => 'datetime',
            'fecha_entrega_estimada' => 'date',
            'fecha_entrega_real' => 'datetime',
            'terminado_at' => 'datetime',
        ];
    }

    /** Estatus en que el trabajo ya se terminó (bien o mal): su ganancia cuenta en el corte de ese día. */
    public const TERMINADOS = [self::LISTO, self::NO_QUEDO, self::RECHAZADO, self::ENTREGADO, self::CANCELADO];

    protected static function booted(): void
    {
        static::creating(function (OrdenReparacion $o) {
            $o->token ??= Str::random(32);
            // folio consecutivo por taller
            $o->folio ??= (int) DB::table('ordenes_reparacion')->where('taller_id', $o->taller_id ?? Taller::actualId())->max('folio') + 1;
            $o->numero_orden ??= 'REP-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        });

        // cuándo se terminó: la primera vez que llega a un estatus final; si regresa a trabajo, se borra
        static::saving(function (OrdenReparacion $o) {
            if (! $o->isDirty('estado')) {
                return;
            }
            if (! in_array($o->estado, self::TERMINADOS, true)) {
                $o->terminado_at = null;
            } elseif ($o->terminado_at === null) {
                $o->terminado_at = now();
            }
        });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(FotoOrden::class, 'orden_reparacion_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'orden_id');
    }

    public function getEstadoTextoAttribute(): string
    {
        return self::ESTADOS_TEXTO[$this->estado] ?? mb_strtoupper((string) $this->estado);
    }

    /** Con algún bloqueo marcado (operador, FRP, iCloud, MDM, Knox) la orden es una liberación. */
    public static function esLiberacion(?array $bloqueos): bool
    {
        return ! empty(array_filter($bloqueos ?? []));
    }

    public function getEsLiberacionAttribute(): bool
    {
        return self::esLiberacion($this->bloqueos);
    }

    /** Estatus que se le pueden poner, según sea liberación o reparación. */
    public static function estadosPara(?array $bloqueos): array
    {
        return self::esLiberacion($bloqueos) ? self::ESTADOS_LIBERACION : self::ESTADOS_REPARACION;
    }

    /** Lo que se le cobra: el costo final si ya se fijó, si no el estimado. */
    public function getTotalAttribute(): float
    {
        return (float) ($this->costo_final ?? $this->costo_estimado ?? 0);
    }

    /**
     * Ganancia del local: lo que se le cobra al cliente menos la refacción y la mano de obra.
     * Si no quedó, se rechazó o se canceló no entra nada, y la ganancia es negativa si se gastó algo.
     */
    public function getGananciaAttribute(): float
    {
        $ingreso = in_array($this->estado, [self::NO_QUEDO, self::CANCELADO, self::RECHAZADO], true) ? 0.0 : $this->total;

        return round($ingreso - (float) $this->costo_refaccion - (float) $this->costo_mano_obra, 2);
    }

    /** Lo que debe. Si no quedó (o se rechazó la liberación), no se cobra. */
    public function getSaldoAttribute(): float
    {
        if (in_array($this->estado, [self::NO_QUEDO, self::CANCELADO, self::RECHAZADO], true)) {
            return 0.0;
        }

        return round($this->total - (float) $this->pagado, 2);
    }

    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereNotIn('estado', [self::ENTREGADO, self::CANCELADO]);
    }

    /** Cambia lo pagado y registra la diferencia como cobro en la caja de hoy. */
    public function cambiarPagado(float $nuevo, string $concepto, string $metodo = Venta::EFECTIVO): void
    {
        $diferencia = round($nuevo - (float) $this->pagado, 2);
        $this->pagado = $nuevo;
        $this->save();
        if ($diferencia != 0) {
            $this->loadMissing('cliente', 'equipo');
            $pago = new Pago([
                'fecha' => now(),
                'orden_id' => $this->id,
                'descripcion' => "Folio {$this->folio} - {$this->cliente?->nombre_completo} - {$this->equipo?->nombre}",
                'concepto' => $diferencia > 0 ? $concepto : 'Corrección (se restó)',
                'monto' => $diferencia,
                'metodo' => $metodo,
                'user_id' => auth()->id(),
            ]);
            $pago->taller_id = $this->taller_id;
            $pago->sucursal_id = $this->sucursal_id;
            $pago->save();
        }
    }
}

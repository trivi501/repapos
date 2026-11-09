<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un cliente del sistema. Todos sus datos llevan taller_id y nadie más los ve. */
class Taller extends Model
{
    public const PRUEBA = 'prueba';
    public const ACTIVO = 'activo';
    public const SUSPENDIDO = 'suspendido';
    public const CORTESIA = 'cortesia';   // gratis, sin vencimiento (por ejemplo, tu propio taller)

    protected $table = 'talleres';
    protected $fillable = ['nombre', 'whatsapp', 'logo', 'estado', 'prueba_hasta', 'pagado_hasta', 'notas'];

    /** Taller fijado a mano (comandos de consola, importación). Si es null se usa el del usuario conectado. */
    public static ?int $forzado = null;

    protected function casts(): array
    {
        return ['prueba_hasta' => 'date', 'pagado_hasta' => 'date'];
    }

    public static function actualId(): ?int
    {
        return static::$forzado ?? auth()->user()?->taller_id;
    }

    protected static function booted(): void
    {
        // todo taller nuevo empieza con una sucursal
        static::created(function (Taller $t) {
            $s = new Sucursal(['nombre' => 'Matriz']);
            $s->taller_id = $t->id;
            $s->save();
        });
    }

    /** El logo como data URI, para meterlo dentro de un PDF (el PDF no puede pedirlo por internet). */
    public function logoDataUri(): ?string
    {
        if (! $this->logo || ! \Illuminate\Support\Facades\Storage::exists($this->logo)) {
            return null;
        }
        return 'data:' . \Illuminate\Support\Facades\Storage::mimeType($this->logo) . ';base64,'
            . base64_encode(\Illuminate\Support\Facades\Storage::get($this->logo));
    }

    /** URL pública del logo (sale en el menú, el comprobante y la consulta del cliente). */
    public function logoUrl(): ?string
    {
        return $this->logo ? route('taller.logo', $this->id).'?v='.$this->updated_at?->timestamp : null;
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class)->withoutGlobalScopes();
    }

    /** ¿Puede usar el sistema hoy? */
    public function vigente(): bool
    {
        return match ($this->estado) {
            self::CORTESIA => true,
            self::PRUEBA => $this->prueba_hasta && $this->prueba_hasta->gte(today()),
            self::ACTIVO => $this->pagado_hasta && $this->pagado_hasta->gte(today()),
            default => false,
        };
    }

    /** Fecha en que se le acaba el acceso (null = no vence). */
    public function vence(): ?\Carbon\Carbon
    {
        return match ($this->estado) {
            self::PRUEBA => $this->prueba_hasta,
            self::ACTIVO => $this->pagado_hasta,
            default => null,
        };
    }

    public function diasRestantes(): ?int
    {
        $v = $this->vence();
        return $v ? (int) today()->diffInDays($v, false) : null;
    }

    public function estadoTexto(): string
    {
        if ($this->estado === self::SUSPENDIDO) {
            return 'Suspendido';
        }
        if ($this->estado === self::CORTESIA) {
            return 'Cortesía (sin vencimiento)';
        }
        $tipo = $this->estado === self::PRUEBA ? 'Prueba gratis' : 'Plan activo';
        return $this->vigente() ? "$tipo hasta " . $this->vence()->format('d/m/Y') : "$tipo vencido el " . $this->vence()?->format('d/m/Y');
    }
}

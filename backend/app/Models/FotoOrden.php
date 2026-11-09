<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FotoOrden extends Model
{
    use DelTaller, Prunable;

    public const CATEGORIAS = ['antes', 'proceso', 'despues'];

    protected $table = 'fotos_orden';

    protected $fillable = [
        'taller_id',
        'orden_reparacion_id',
        'lote',
        'categoria',
        'ruta',
        'nombre_original',
        'tamano',
        'user_id',
    ];

    protected $appends = ['url'];

    protected $hidden = ['ruta', 'lote', 'user_id'];

    protected static function booted(): void
    {
        static::deleting(fn (FotoOrden $foto) => Storage::disk('public')->delete($foto->ruta));
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenReparacion::class, 'orden_reparacion_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->ruta);
    }

    /**
     * Borradores que nunca se asociaron a una orden (se canceló el formulario).
     * Se eliminan con `php artisan model:prune`, programado a diario.
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->whereNull('orden_reparacion_id')->where('created_at', '<', now()->subDay());
    }
}

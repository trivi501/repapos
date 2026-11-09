<?php

namespace App\Models\Concerns;

use App\Models\Taller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Separa los datos por taller: cada consulta solo ve las filas del taller del usuario conectado
 * (también al buscar por id en una URL, así un taller no puede abrir lo de otro),
 * y todo lo que se crea se guarda en su taller.
 */
trait DelTaller
{
    protected static function bootDelTaller(): void
    {
        static::addGlobalScope('taller', function (Builder $q) {
            if ($id = Taller::actualId()) {
                $q->where($q->getModel()->getTable() . '.taller_id', $id);
            }
        });

        static::creating(function ($modelo) {
            if (! $modelo->taller_id) {
                $modelo->taller_id = Taller::actualId()
                    ?? throw new \LogicException('No se sabe a qué taller pertenece este registro.');
            }
        });
    }

    public function taller(): BelongsTo
    {
        return $this->belongsTo(Taller::class);
    }
}

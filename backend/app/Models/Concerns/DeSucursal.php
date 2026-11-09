<?php

namespace App\Models\Concerns;

use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos que son de cada sucursal (teléfonos, órdenes, caja, inventario): solo se ven los de la sucursal
 * en la que se está trabajando, y lo que se crea queda en ella. Se usa junto con DelTaller.
 */
trait DeSucursal
{
    protected static function bootDeSucursal(): void
    {
        static::addGlobalScope('sucursal', function (Builder $q) {
            if ($id = Sucursal::actualId()) {
                $q->where($q->getModel()->getTable() . '.sucursal_id', $id);
            }
        });

        static::creating(function ($modelo) {
            if (! $modelo->sucursal_id) {
                $modelo->sucursal_id = Sucursal::actualId()
                    ?? throw new \LogicException('No se sabe a qué sucursal pertenece este registro.');
            }
        });
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}

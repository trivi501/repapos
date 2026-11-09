<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cliente final (mostrador). Se comparte entre las sucursales del taller. */
class Cliente extends Model
{
    use DelTaller, HasFactory;

    protected $fillable = [
        'nombre',
        'apellido',
        'telefono',
        'telefono2',
        'email',
        'direccion',
        'notas',
    ];

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class);
    }

    public function ordenesReparacion(): HasMany
    {
        return $this->hasMany(OrdenReparacion::class);
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim($this->nombre.' '.$this->apellido);
    }
}

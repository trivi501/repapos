<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipo extends Model
{
    use DelTaller, HasFactory;

    protected $fillable = [
        'cliente_id',
        'tipo',
        'marca',
        'modelo',
        'imei',
        'color',
        'patron_desbloqueo',
        'accesorios',
        'condicion_inicial',
    ];

    protected function casts(): array
    {
        return ['patron_desbloqueo' => 'encrypted'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ordenesReparacion(): HasMany
    {
        return $this->hasMany(OrdenReparacion::class);
    }

    public function getNombreAttribute(): string
    {
        return trim($this->marca.' '.$this->modelo);
    }
}

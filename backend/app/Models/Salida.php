<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Model;

class Salida extends Model
{
    use DelTaller, DeSucursal;

    public const CATEGORIAS = [
        'Refacciones', 'Comida', 'Pasajes / gasolina', 'Servicios (luz, internet)', 'Renta', 'Sueldos', 'Devoluciones', 'Otro',
    ];

    protected $fillable = ['fecha', 'concepto', 'categoria', 'monto', 'notas', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime', 'monto' => 'decimal:2'];
    }
}

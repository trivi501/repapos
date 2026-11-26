<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;

/** Un servicio que el taller hace seguido, con su precio (ej. "FRP por ISP" $250). */
class PlantillaServicio extends Model
{
    use DelTaller;

    protected $table = 'plantillas_servicio';
    protected $fillable = ['nombre', 'precio'];
    protected $hidden = ['taller_id'];
    protected $casts = ['precio' => 'float'];
}

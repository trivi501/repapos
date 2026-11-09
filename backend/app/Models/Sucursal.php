<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    use DelTaller;

    public const CABECERA = 'X-Sucursal';

    protected $table = 'sucursales';

    protected $fillable = ['nombre', 'direccion', 'telefono'];

    /** Sucursal fijada a mano (consola, importación). */
    public static ?int $forzada = null;

    /**
     * Sucursal en la que se está trabajando:
     * la fija del usuario, o la que eligió en el menú (cabecera X-Sucursal), o la primera de su taller.
     * Se calcula una vez por petición.
     */
    public static function actualId(): ?int
    {
        if (static::$forzada) {
            return static::$forzada;
        }
        $u = auth()->user();
        if (! $u) {
            return null;
        }
        $req = request();
        if ($req->attributes->has('sucursal_id')) {
            return $req->attributes->get('sucursal_id');
        }
        $id = $u->sucursal_id;
        if (! $id) {
            $elegida = (int) $req->header(self::CABECERA);
            $ids = static::withoutGlobalScopes()->where('taller_id', $u->taller_id)->orderBy('id')->pluck('id');
            $id = $elegida && $ids->contains($elegida) ? $elegida : $ids->first();
        }
        $req->attributes->set('sucursal_id', $id);

        return $id;
    }

    public static function actual(): ?self
    {
        $id = static::actualId();

        return $id ? static::withoutGlobalScopes()->find($id) : null;
    }
}

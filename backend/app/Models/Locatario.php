<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Locatario extends Model
{
    use DelTaller;

    protected $fillable = ['nombre', 'whatsapp'];

    public function telefonos(): HasMany
    {
        return $this->hasMany(Telefono::class);
    }

    public function creditos(): HasMany
    {
        return $this->hasMany(Credito::class);
    }

    public function saldoAFavor(): float
    {
        return round((float) $this->creditos()->sum('monto'), 2);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    /** Lo que debe: teléfonos sin pagar + productos que se llevó a crédito. */
    public function deuda(): float
    {
        return round((float) $this->telefonos()->conSaldo()->selectRaw('SUM(total - pagado) as s')->value('s')
            + $this->deudaVentas(), 2);
    }

    public function deudaVentas(): float
    {
        return round((float) $this->ventas()->conSaldo()->selectRaw('SUM(total - devuelto - pagado) as s')->value('s'), 2);
    }

    /** Busca por nombre sin importar mayúsculas (dentro del taller); si no existe lo crea. */
    public static function porNombre(string $nombre): self
    {
        $nombre = trim($nombre);
        return static::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first()
            ?? static::create(['nombre' => $nombre]);
    }
}

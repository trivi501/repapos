<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Refacción o accesorio del catálogo del taller. Las existencias se llevan por sucursal. */
class Producto extends Model
{
    use DelTaller;

    public const CATEGORIAS = ['Pantallas', 'Baterías', 'Centros de carga', 'Tapas', 'Cámaras', 'Flex', 'Accesorios', 'Micas', 'Fundas', 'Cargadores', 'Otro'];

    protected $fillable = ['codigo', 'nombre', 'categoria', 'costo', 'precio', 'precio_locatario', 'minimo'];

    protected function casts(): array
    {
        return ['costo' => 'decimal:2', 'precio' => 'decimal:2', 'precio_locatario' => 'decimal:2', 'minimo' => 'integer'];
    }

    /** Precio según a quién se le vende; si no tiene precio de locatario, el de cliente final. */
    public function precioPara(string $tipoVenta): float
    {
        return $tipoVenta === Venta::LOCATARIO && $this->precio_locatario !== null
            ? (float) $this->precio_locatario
            : (float) $this->precio;
    }

    /** Existencia en la sucursal en la que se está trabajando. */
    public function existencia(): HasOne
    {
        return $this->hasOne(Existencia::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }
}

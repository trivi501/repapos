<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use App\Models\Concerns\DeSucursal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Existencia extends Model
{
    use DelTaller, DeSucursal;

    protected $fillable = ['producto_id', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}

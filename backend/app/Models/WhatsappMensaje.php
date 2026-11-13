<?php

namespace App\Models;

use App\Models\Concerns\DelTaller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un mensaje mandado por la API de WhatsApp Cloud: queda registrado aunque falle. */
class WhatsappMensaje extends Model
{
    use DelTaller;

    public const ENVIADO = 'enviado';
    public const FALLIDO = 'fallido';
    public const PLANTILLA = 'plantilla';
    public const TEXTO = 'texto';

    protected $table = 'whatsapp_mensajes';

    protected $fillable = ['telefono', 'tipo', 'texto', 'estado', 'error', 'meta_id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

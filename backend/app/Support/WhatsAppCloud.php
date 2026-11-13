<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\WhatsappMensaje;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envío directo por la API de WhatsApp Cloud (Meta), con las credenciales de cada taller.
 *
 * - Con plantilla: sirve para avisar en cualquier momento. La plantilla aprobada en Meta debe tener una
 *   variable {{1}} en el cuerpo: ahí va el mensaje completo (Meta no acepta saltos de línea en variables).
 * - Sin plantilla: texto libre, que Meta solo entrega si la persona escribió en las últimas 24 horas.
 */
class WhatsAppCloud
{
    public const TOKEN = 'wa_api_token';           // cifrado con APP_KEY
    public const NUMERO_ID = 'wa_api_phone_id';
    public const PLANTILLA = 'wa_api_plantilla';
    public const IDIOMA = 'wa_api_idioma';
    public const DUENO = 'wa_api_dueno';           // recibe lo que no tiene destinatario (el corte de caja)
    public const ACTIVA = 'wa_api_activo';

    public static function version(): string
    {
        return (string) config('services.whatsapp.version', 'v23.0');
    }

    /** Credenciales del taller del usuario conectado; null si la API no está activa. */
    public static function config(): ?array
    {
        if (Ajuste::valor(self::ACTIVA) !== '1') {
            return null;
        }
        $token = static::token(Ajuste::valor(self::TOKEN));
        $numeroId = Ajuste::valor(self::NUMERO_ID);
        if (! $token || $numeroId === '') {
            return null;
        }

        return [
            'token' => $token,
            'numero_id' => $numeroId,
            'plantilla' => Ajuste::valor(self::PLANTILLA),
            'idioma' => Ajuste::valor(self::IDIOMA, 'es_MX'),
            'dueno' => Ajuste::valor(self::DUENO),
        ];
    }

    /** Para el inicio de sesión (todavía no hay taller elegido en las consultas). */
    public static function activaPara(int $tallerId): bool
    {
        return Ajuste::valorDe($tallerId, self::ACTIVA) === '1'
            && Ajuste::valorDe($tallerId, self::NUMERO_ID) !== ''
            && static::token(Ajuste::valorDe($tallerId, self::TOKEN)) !== null;
    }

    public static function token(string $cifrado): ?string
    {
        if ($cifrado === '') {
            return null;
        }
        try {
            return Crypt::decryptString($cifrado);
        } catch (DecryptException) {
            return null;   // se cambió APP_KEY: hay que capturar el token otra vez
        }
    }

    public static function cifrar(string $token): string
    {
        return Crypt::encryptString($token);
    }

    /** Le pregunta a Meta por el número: comprueba el token y el ID. */
    public static function probar(string $token, string $numeroId): array
    {
        try {
            $r = Http::withToken($token)->acceptJson()->timeout(15)
                ->get(static::url($numeroId), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con WhatsApp. Revisa el internet del servidor.');
        }
        if ($r->failed()) {
            // pegaron el ID de la cuenta de WhatsApp Business (o de la app) en vez del ID del número
            if ((int) $r->json('error.code') === 100 && str_contains((string) $r->json('error.message'), 'nonexisting field')) {
                throw new RuntimeException(static::sugerirNumero($token, $numeroId));
            }
            throw new RuntimeException(static::error($r));
        }

        return [
            'numero' => $r->json('display_phone_number'),
            'nombre' => $r->json('verified_name'),
            'calidad' => $r->json('quality_rating'),
        ];
    }

    /** Si el ID es de una cuenta de WhatsApp Business, le pregunta a Meta qué números tiene y cuál es su ID. */
    private static function sugerirNumero(string $token, string $id): string
    {
        $base = 'Ese ID no es el de un número de teléfono: parece el de tu cuenta de WhatsApp Business o el de la app.';
        try {
            $r = Http::withToken($token)->acceptJson()->timeout(15)
                ->get(static::url($id).'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name']);
        } catch (ConnectionException) {
            $r = null;
        }
        $numeros = $r?->successful() ? (array) $r->json('data', []) : [];
        if (! $numeros) {
            return $base.' Copia el "Identificador del número de teléfono" (Phone number ID) que aparece en Meta → WhatsApp → Configuración de la API, debajo del número.';
        }
        $lista = collect($numeros)->map(fn ($n) => ($n['display_phone_number'] ?? '?').' ('.($n['verified_name'] ?? 'sin nombre').') → ID '.($n['id'] ?? '?'))->implode('; ');

        return $base.' Tus números en esa cuenta: '.$lista.'. Pega ese ID en "ID del número de teléfono" y guarda otra vez.';
    }

    /** Manda el mensaje y lo registra, haya salido o no. */
    public static function enviar(string $numero, string $texto): WhatsappMensaje
    {
        $c = static::config() ?? throw new RuntimeException('La API de WhatsApp no está activa en este taller.');
        $conPlantilla = $c['plantilla'] !== '';

        $payload = ['messaging_product' => 'whatsapp', 'to' => $numero];
        $payload += $conPlantilla
            ? ['type' => 'template', 'template' => [
                'name' => $c['plantilla'],
                'language' => ['code' => $c['idioma'] ?: 'es_MX'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => static::paraPlantilla($texto)]]]],
            ]]
            : ['type' => 'text', 'text' => ['body' => mb_substr($texto, 0, 4096), 'preview_url' => true]];

        $mensaje = new WhatsappMensaje([
            'telefono' => $numero, 'texto' => $texto, 'user_id' => auth()->id(),
            'tipo' => $conPlantilla ? WhatsappMensaje::PLANTILLA : WhatsappMensaje::TEXTO,
        ]);
        try {
            $r = Http::withToken($c['token'])->acceptJson()->timeout(20)->post(static::url($c['numero_id']).'/messages', $payload);
            if ($r->successful()) {
                $mensaje->estado = WhatsappMensaje::ENVIADO;
                $mensaje->meta_id = $r->json('messages.0.id');
            } else {
                $mensaje->estado = WhatsappMensaje::FALLIDO;
                $mensaje->error = mb_substr(static::error($r), 0, 255);
            }
        } catch (ConnectionException) {
            $mensaje->estado = WhatsappMensaje::FALLIDO;
            $mensaje->error = 'No se pudo conectar con WhatsApp. Revisa el internet del servidor.';
        }
        $mensaje->save();

        return $mensaje;
    }

    /** Meta no acepta saltos de línea, tabuladores ni más de 4 espacios seguidos en una variable. */
    public static function paraPlantilla(string $texto): string
    {
        $una = preg_replace('/\s*\R+\s*/u', ' | ', trim($texto));
        $una = preg_replace('/[\t ]{2,}/u', ' ', $una);

        return mb_substr($una, 0, 1000);
    }

    private static function url(string $numeroId): string
    {
        return 'https://graph.facebook.com/'.static::version().'/'.rawurlencode($numeroId);
    }

    /** El error de Meta, en palabras del taller. */
    private static function error(Response $r): string
    {
        $codigo = (int) ($r->json('error.code') ?? $r->status());
        $texto = $r->json('error.error_user_msg') ?? $r->json('error.message') ?? 'error desconocido';

        return match ($codigo) {
            190 => 'El token no es válido o ya venció. Usa un token permanente de un usuario del sistema.',
            131047 => 'Pasaron más de 24 horas desde que esta persona escribió: para avisarle hace falta una plantilla aprobada.',
            132000, 132001 => 'La plantilla no existe, no está aprobada en ese idioma o no tiene la variable {{1}}.',
            131026 => 'Ese número no tiene WhatsApp o no puede recibir mensajes.',
            131030 => 'Ese número no está en la lista de prueba de tu app de Meta: agrégalo o pasa la app a producción.',
            133010 => 'El número del negocio todavía no está registrado en la API de WhatsApp.',
            default => "WhatsApp respondió: {$texto} (código {$codigo})",
        };
    }
}

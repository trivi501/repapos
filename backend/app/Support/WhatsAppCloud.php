<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\Taller;
use App\Models\WhatsappMensaje;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envío directo de WhatsApp desde el servidor, con uno de dos proveedores (cada taller elige):
 *
 * - "evolution": Evolution API (open source, en el mismo VPS). Se vincula el número escaneando un QR, como
 *   WhatsApp Web. No es oficial: WhatsApp puede bloquear el número si se abusa (mensajes masivos, reportes).
 * - "meta": API oficial de WhatsApp Cloud. Con plantilla, el mensaje va en la variable {{1}} (Meta no acepta
 *   saltos de línea ahí); sin plantilla solo llega a quien escribió en las últimas 24 horas.
 */
class WhatsAppCloud
{
    public const PROVEEDOR = 'wa_proveedor';      // evolution o meta
    public const ACTIVA = 'wa_api_activo';
    public const DUENO = 'wa_api_dueno';           // recibe lo que no tiene destinatario (el corte de caja)
    // Meta
    public const TOKEN = 'wa_api_token';           // cifrado con APP_KEY
    public const NUMERO_ID = 'wa_api_phone_id';
    public const PLANTILLA = 'wa_api_plantilla';
    public const IDIOMA = 'wa_api_idioma';
    // Evolution API
    public const INSTANCIA = 'wa_evo_instancia';

    public const EVOLUTION = 'evolution';
    public const META = 'meta';

    public static function version(): string
    {
        return (string) config('services.whatsapp.version', 'v23.0');
    }

    /** El servidor tiene Evolution API instalada (EVOLUTION_URL y EVOLUTION_API_KEY en el .env). */
    public static function evolutionDisponible(): bool
    {
        return filled(config('services.evolution.url')) && filled(config('services.evolution.key'));
    }

    public static function proveedor(): string
    {
        return Ajuste::valor(self::PROVEEDOR, self::META) === self::EVOLUTION ? self::EVOLUTION : self::META;
    }

    /** Credenciales del taller del usuario conectado; null si el envío directo no está activo. */
    public static function config(): ?array
    {
        if (Ajuste::valor(self::ACTIVA) !== '1') {
            return null;
        }
        $dueno = Ajuste::valor(self::DUENO);

        if (static::proveedor() === self::EVOLUTION) {
            return static::evolutionDisponible()
                ? ['proveedor' => self::EVOLUTION, 'instancia' => static::instancia(), 'dueno' => $dueno]
                : null;
        }

        $token = static::token(Ajuste::valor(self::TOKEN));
        $numeroId = Ajuste::valor(self::NUMERO_ID);
        if (! $token || $numeroId === '') {
            return null;
        }

        return [
            'proveedor' => self::META,
            'token' => $token,
            'numero_id' => $numeroId,
            'plantilla' => Ajuste::valor(self::PLANTILLA),
            'idioma' => Ajuste::valor(self::IDIOMA, 'es_MX'),
            'dueno' => $dueno,
        ];
    }

    /** Para el inicio de sesión (todavía no hay taller elegido en las consultas). */
    public static function activaPara(int $tallerId): bool
    {
        if (Ajuste::valorDe($tallerId, self::ACTIVA) !== '1') {
            return false;
        }
        if (Ajuste::valorDe($tallerId, self::PROVEEDOR, self::META) === self::EVOLUTION) {
            return static::evolutionDisponible();
        }

        return Ajuste::valorDe($tallerId, self::NUMERO_ID) !== ''
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

    /** Manda el mensaje y lo registra, haya salido o no. */
    public static function enviar(string $numero, string $texto): WhatsappMensaje
    {
        $c = static::config() ?? throw new RuntimeException('El envío directo de WhatsApp no está activo en este taller.');

        return $c['proveedor'] === self::EVOLUTION
            ? static::enviarEvolution($c, $numero, $texto)
            : static::enviarMeta($c, $numero, $texto);
    }

    // ------------------------------------------------------------------ Evolution API

    /** Nombre de la instancia de este taller en Evolution API (una por taller). */
    public static function instancia(): string
    {
        $guardada = Ajuste::valor(self::INSTANCIA);
        if ($guardada !== '') {
            return $guardada;
        }
        $nombre = 'repapos-taller-'.Taller::actualId();
        Ajuste::poner(self::INSTANCIA, $nombre);

        return $nombre;
    }

    private static function evolution(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->acceptJson()->timeout(20);
    }

    /** open = conectado, connecting = esperando el QR, close = desconectado, no_existe = nunca se vinculó. */
    public static function evolutionEstado(): string
    {
        try {
            $r = static::evolution()->get('instance/connectionState/'.rawurlencode(static::instancia()));
        } catch (ConnectionException) {
            throw new RuntimeException('No se pudo conectar con Evolution API. Revisa que esté corriendo en el servidor.');
        }
        if ($r->status() === 404) {
            return 'no_existe';
        }
        if ($r->failed()) {
            throw new RuntimeException(static::errorEvolution($r));
        }

        return (string) ($r->json('instance.state') ?? $r->json('state') ?? 'close');
    }

    /** Crea la instancia si no existe y regresa el QR para escanear (null si ya está conectado). */
    public static function evolutionQr(): array
    {
        $instancia = static::instancia();
        $estado = static::evolutionEstado();
        if ($estado === 'open') {
            return ['estado' => 'open', 'qr' => null];
        }
        try {
            if ($estado === 'no_existe') {
                $creada = static::evolution()->post('instance/create', [
                    'instanceName' => $instancia, 'qrcode' => true, 'integration' => 'WHATSAPP-BAILEYS',
                ]);
                if ($creada->failed()) {
                    throw new RuntimeException(static::errorEvolution($creada));
                }
            }
            $r = static::evolution()->get('instance/connect/'.rawurlencode($instancia));
        } catch (ConnectionException) {
            throw new RuntimeException('No se pudo conectar con Evolution API. Revisa que esté corriendo en el servidor.');
        }
        if ($r->failed()) {
            throw new RuntimeException(static::errorEvolution($r));
        }
        $qr = $r->json('base64') ?? $r->json('qrcode.base64');

        return ['estado' => 'connecting', 'qr' => $qr, 'codigo' => $r->json('pairingCode')];
    }

    /** Cierra la sesión de WhatsApp en Evolution (el número queda desvinculado). */
    public static function evolutionDesconectar(): void
    {
        try {
            static::evolution()->delete('instance/logout/'.rawurlencode(static::instancia()));
        } catch (ConnectionException) {
            throw new RuntimeException('No se pudo conectar con Evolution API. Revisa que esté corriendo en el servidor.');
        }
    }

    private static function enviarEvolution(array $c, string $numero, string $texto): WhatsappMensaje
    {
        $mensaje = new WhatsappMensaje(['telefono' => $numero, 'texto' => $texto, 'user_id' => auth()->id(), 'tipo' => WhatsappMensaje::TEXTO]);
        try {
            $r = static::evolution()->post('message/sendText/'.rawurlencode($c['instancia']), ['number' => $numero, 'text' => $texto]);
            if ($r->successful()) {
                $mensaje->estado = WhatsappMensaje::ENVIADO;
                $mensaje->meta_id = $r->json('key.id');
            } else {
                $mensaje->estado = WhatsappMensaje::FALLIDO;
                $mensaje->error = mb_substr(static::errorEvolution($r), 0, 255);
            }
        } catch (ConnectionException) {
            $mensaje->estado = WhatsappMensaje::FALLIDO;
            $mensaje->error = 'No se pudo conectar con Evolution API. Revisa que esté corriendo en el servidor.';
        }
        $mensaje->save();

        return $mensaje;
    }

    private static function errorEvolution(Response $r): string
    {
        $detalle = $r->json('response.message') ?? $r->json('message') ?? $r->json('error') ?? $r->body();
        $texto = is_array($detalle) ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : (string) $detalle;

        return match (true) {
            $r->status() === 401 => 'Evolution API rechazó la clave: revisa EVOLUTION_API_KEY en el .env del servidor.',
            str_contains($texto, '"exists":false') => 'Ese número no tiene WhatsApp.',
            str_contains(mb_strtolower($texto), 'connection closed') || str_contains(mb_strtolower($texto), 'not connected')
                => 'El WhatsApp del taller se desconectó: escanea el QR otra vez en Configuración.',
            default => 'Evolution API respondió: '.mb_substr($texto, 0, 180).' (código '.$r->status().')',
        };
    }

    // ------------------------------------------------------------------ Meta (oficial)

    /** Le pregunta a Meta por el número: comprueba el token y el ID. */
    public static function probar(string $token, string $numeroId): array
    {
        try {
            $r = Http::withToken($token)->acceptJson()->timeout(15)
                ->get(static::url($numeroId), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        } catch (ConnectionException) {
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

    private static function enviarMeta(array $c, string $numero, string $texto): WhatsappMensaje
    {
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

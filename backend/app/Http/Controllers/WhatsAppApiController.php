<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\WhatsappMensaje;
use App\Support\WhatsApp;
use App\Support\WhatsAppCloud;
use Illuminate\Http\Request;
use RuntimeException;

/** Conexión de cada taller con la API de WhatsApp Cloud y envío de mensajes. */
class WhatsAppApiController extends Controller
{
    /** La configuración, sin el token: solo se dice si hay uno y sus últimos 4 caracteres. */
    public function show()
    {
        $token = WhatsAppCloud::token(Ajuste::valor(WhatsAppCloud::TOKEN));

        return response()->json([
            'activa' => WhatsAppCloud::config() !== null,
            'encendida' => Ajuste::valor(WhatsAppCloud::ACTIVA) === '1',
            'tiene_token' => $token !== null,
            'token_final' => $token ? mb_substr($token, -4) : null,
            // el token guardado ya no se puede leer (cambió APP_KEY)
            'token_ilegible' => Ajuste::valor(WhatsAppCloud::TOKEN) !== '' && $token === null,
            'numero_id' => Ajuste::valor(WhatsAppCloud::NUMERO_ID),
            'plantilla' => Ajuste::valor(WhatsAppCloud::PLANTILLA),
            'idioma' => Ajuste::valor(WhatsAppCloud::IDIOMA, 'es_MX'),
            'dueno' => Ajuste::valor(WhatsAppCloud::DUENO),
            'mensajes' => WhatsappMensaje::with('user:id,name')->latest('id')->limit(30)
                ->get(['id', 'telefono', 'tipo', 'texto', 'estado', 'error', 'user_id', 'created_at']),
        ]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'numero_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'token' => ['nullable', 'string', 'min:20', 'max:2000'],
            'quitar_token' => ['nullable', 'boolean'],
            'plantilla' => ['nullable', 'regex:/^[a-z0-9_]{1,512}$/'],
            'idioma' => ['nullable', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'dueno' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
            'encendida' => ['nullable', 'boolean'],
        ], [
            'numero_id.regex' => 'El ID del número son solo dígitos (lo ves en Meta, en WhatsApp → Configuración de la API).',
            'token.min' => 'Ese token es muy corto: copia el token completo.',
            'plantilla.regex' => 'El nombre de la plantilla va en minúsculas, sin espacios ni acentos (ej. aviso_taller).',
            'idioma.regex' => 'El idioma va como es_MX o es.',
            'dueno.regex' => 'El número del dueño debe tener al menos 10 dígitos.',
        ]);

        $token = trim((string) ($d['token'] ?? ''));
        if ($r->boolean('quitar_token')) {
            Ajuste::poner(WhatsAppCloud::TOKEN, '');
        } elseif ($token !== '') {
            Ajuste::poner(WhatsAppCloud::TOKEN, WhatsAppCloud::cifrar($token));
        }
        Ajuste::poner(WhatsAppCloud::NUMERO_ID, (string) ($d['numero_id'] ?? ''));
        Ajuste::poner(WhatsAppCloud::PLANTILLA, (string) ($d['plantilla'] ?? ''));
        Ajuste::poner(WhatsAppCloud::IDIOMA, (string) ($d['idioma'] ?? '') ?: 'es_MX');
        Ajuste::poner(WhatsAppCloud::DUENO, preg_replace('/\D/', '', (string) ($d['dueno'] ?? '')));

        // solo se enciende si Meta reconoce el token y el número
        $info = null;
        if ($r->boolean('encendida')) {
            $guardado = WhatsAppCloud::token(Ajuste::valor(WhatsAppCloud::TOKEN));
            if (! $guardado || ($d['numero_id'] ?? '') === '') {
                Ajuste::poner(WhatsAppCloud::ACTIVA, '0');

                return response()->json(['message' => 'Para activarla hacen falta el token y el ID del número.'], 422);
            }
            try {
                $info = WhatsAppCloud::probar($guardado, $d['numero_id']);
            } catch (RuntimeException $e) {
                Ajuste::poner(WhatsAppCloud::ACTIVA, '0');

                return response()->json(['message' => 'Se guardó, pero no se activó: '.$e->getMessage()], 422);
            }
        }
        Ajuste::poner(WhatsAppCloud::ACTIVA, $r->boolean('encendida') ? '1' : '0');

        return response()->json([
            'message' => $info
                ? "WhatsApp conectado: {$info['nombre']} ({$info['numero']}). Los botones de WhatsApp ahora envían directo."
                : 'Configuración de WhatsApp guardada (apagada: los botones abren WhatsApp como antes).',
            'info' => $info,
        ]);
    }

    /** Comprueba con Meta el token y el número que están guardados. */
    public function probar()
    {
        $token = WhatsAppCloud::token(Ajuste::valor(WhatsAppCloud::TOKEN));
        $numeroId = Ajuste::valor(WhatsAppCloud::NUMERO_ID);
        if (! $token || $numeroId === '') {
            return response()->json(['message' => 'Primero guarda el token y el ID del número.'], 422);
        }
        try {
            $info = WhatsAppCloud::probar($token, $numeroId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Conexión correcta: {$info['nombre']} ({$info['numero']}).", 'info' => $info]);
    }

    /** Lo que antes abría WhatsApp, ahora se manda directo. Sin número va al dueño (el corte de caja). */
    public function enviar(Request $r)
    {
        $d = $r->validate([
            'numero' => ['nullable', 'string', 'max:30'],
            'texto' => ['required', 'string', 'max:4096'],
        ]);
        $config = WhatsAppCloud::config();
        if (! $config) {
            return response()->json(['message' => 'La API de WhatsApp no está activa en este taller.'], 422);
        }
        $numero = WhatsApp::numero($d['numero'] ?? null) ?? WhatsApp::numero($config['dueno']);
        if (! $numero) {
            return response()->json(['message' => 'Este mensaje no tiene a quién mandarse. Captura el número del dueño en Configuración → WhatsApp Business.'], 422);
        }

        $mensaje = WhatsAppCloud::enviar($numero, $d['texto']);
        if ($mensaje->estado !== WhatsappMensaje::ENVIADO) {
            return response()->json(['message' => "No se envió a {$numero}: {$mensaje->error}"], 422);
        }

        return response()->json(['message' => "Mensaje enviado por WhatsApp a {$numero}."]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\WhatsappMensaje;
use App\Support\WhatsApp;
use App\Support\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Envío directo de WhatsApp de cada taller: Evolution API (QR) o la API oficial de Meta. */
class WhatsAppApiController extends Controller
{
    /** La configuración, sin el token: solo se dice si hay uno y sus últimos 4 caracteres. */
    public function show()
    {
        $token = WhatsAppCloud::token(Ajuste::valor(WhatsAppCloud::TOKEN));

        return response()->json([
            'proveedor' => WhatsAppCloud::proveedor(),
            'activa' => WhatsAppCloud::config() !== null,
            'encendida' => Ajuste::valor(WhatsAppCloud::ACTIVA) === '1',
            'dueno' => Ajuste::valor(WhatsAppCloud::DUENO),
            'evolution' => [
                'disponible' => WhatsAppCloud::evolutionDisponible(),
                'instancia' => WhatsAppCloud::evolutionDisponible() ? WhatsAppCloud::instancia() : null,
            ],
            'tiene_token' => $token !== null,
            'token_final' => $token ? mb_substr($token, -4) : null,
            // el token guardado ya no se puede leer (cambió APP_KEY)
            'token_ilegible' => Ajuste::valor(WhatsAppCloud::TOKEN) !== '' && $token === null,
            'numero_id' => Ajuste::valor(WhatsAppCloud::NUMERO_ID),
            'plantilla' => Ajuste::valor(WhatsAppCloud::PLANTILLA),
            'idioma' => Ajuste::valor(WhatsAppCloud::IDIOMA, 'es_MX'),
        ]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'proveedor' => ['nullable', Rule::in([WhatsAppCloud::EVOLUTION, WhatsAppCloud::META])],
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

        $proveedor = $d['proveedor'] ?? WhatsAppCloud::proveedor();
        Ajuste::poner(WhatsAppCloud::PROVEEDOR, $proveedor);
        Ajuste::poner(WhatsAppCloud::DUENO, preg_replace('/\D/', '', (string) ($d['dueno'] ?? '')));

        $token = trim((string) ($d['token'] ?? ''));
        if ($r->boolean('quitar_token')) {
            Ajuste::poner(WhatsAppCloud::TOKEN, '');
        } elseif ($token !== '') {
            Ajuste::poner(WhatsAppCloud::TOKEN, WhatsAppCloud::cifrar($token));
        }
        Ajuste::poner(WhatsAppCloud::NUMERO_ID, (string) ($d['numero_id'] ?? ''));
        Ajuste::poner(WhatsAppCloud::PLANTILLA, (string) ($d['plantilla'] ?? ''));
        Ajuste::poner(WhatsAppCloud::IDIOMA, (string) ($d['idioma'] ?? '') ?: 'es_MX');

        if (! $r->boolean('encendida')) {
            Ajuste::poner(WhatsAppCloud::ACTIVA, '0');

            return response()->json(['message' => 'Configuración de WhatsApp guardada (apagada: los botones abren WhatsApp como antes).']);
        }

        // solo se enciende si de verdad puede enviar
        try {
            $conectado = $proveedor === WhatsAppCloud::EVOLUTION ? $this->verificarEvolution() : $this->verificarMeta($d['numero_id'] ?? '');
        } catch (RuntimeException $e) {
            Ajuste::poner(WhatsAppCloud::ACTIVA, '0');

            return response()->json(['message' => 'Se guardó, pero no se activó: '.$e->getMessage()], 422);
        }
        Ajuste::poner(WhatsAppCloud::ACTIVA, '1');

        return response()->json(['message' => "WhatsApp conectado{$conectado}. Los botones de WhatsApp ahora envían directo."]);
    }

    private function verificarEvolution(): string
    {
        if (! WhatsAppCloud::evolutionDisponible()) {
            throw new RuntimeException('el servidor no tiene Evolution API instalada (falta EVOLUTION_URL y EVOLUTION_API_KEY en el .env).');
        }
        if (WhatsAppCloud::evolutionEstado() !== 'open') {
            throw new RuntimeException('primero vincula el WhatsApp del taller escaneando el código QR.');
        }

        return ' por código QR';
    }

    private function verificarMeta(string $numeroId): string
    {
        $guardado = WhatsAppCloud::token(Ajuste::valor(WhatsAppCloud::TOKEN));
        if (! $guardado || $numeroId === '') {
            throw new RuntimeException('para activarla hacen falta el token y el ID del número.');
        }
        $info = WhatsAppCloud::probar($guardado, $numeroId);

        return ": {$info['nombre']} ({$info['numero']})";
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

    // ------------------------------------------------------------------ Evolution API (QR)

    public function evolutionEstado()
    {
        return $this->conEvolution(fn () => ['estado' => WhatsAppCloud::evolutionEstado()]);
    }

    /** Regresa el QR para vincular el WhatsApp del taller (se escanea desde WhatsApp → Dispositivos vinculados). */
    public function evolutionConectar()
    {
        return $this->conEvolution(fn () => WhatsAppCloud::evolutionQr());
    }

    public function evolutionDesconectar()
    {
        return $this->conEvolution(function () {
            WhatsAppCloud::evolutionDesconectar();
            if (WhatsAppCloud::proveedor() === WhatsAppCloud::EVOLUTION) {
                Ajuste::poner(WhatsAppCloud::ACTIVA, '0');   // sin número vinculado ya no puede enviar
            }

            return ['message' => 'WhatsApp desvinculado. Los botones vuelven a abrir WhatsApp.'];
        });
    }

    private function conEvolution(callable $accion)
    {
        if (! WhatsAppCloud::evolutionDisponible()) {
            return response()->json(['message' => 'El servidor no tiene Evolution API instalada. Sigue la guía deploy/EVOLUTION-API.md.'], 422);
        }
        try {
            return response()->json($accion());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ------------------------------------------------------------------ mensajes enviados

    /** Historial de mensajes del taller, con filtros. */
    public function mensajes(Request $r)
    {
        $q = WhatsappMensaje::with('user:id,name')
            ->when(in_array($r->input('estado'), [WhatsappMensaje::ENVIADO, WhatsappMensaje::FALLIDO], true), fn ($w) => $w->where('estado', $r->input('estado')))
            ->when($r->filled('desde'), fn ($w) => $w->where('created_at', '>=', $r->date('desde')->startOfDay()))
            ->when($r->filled('hasta'), fn ($w) => $w->where('created_at', '<', $r->date('hasta')->startOfDay()->addDay()))
            ->when($r->filled('q'), function ($w) use ($r) {
                $texto = '%'.$r->input('q').'%';
                $w->where(fn ($x) => $x->where('telefono', 'like', $texto)->orWhere('texto', 'like', $texto));
            });

        $conteo = (clone $q)->toBase()->reorder()
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as enviados', [WhatsappMensaje::ENVIADO])->first();

        return response()->json($q->latest('id')->paginate(50)->toArray() + [
            'conteo' => ['total' => (int) $conteo->total, 'enviados' => (int) $conteo->enviados, 'fallidos' => (int) $conteo->total - (int) $conteo->enviados],
            'activa' => WhatsAppCloud::config() !== null,
        ]);
    }

    /** Vuelve a mandar un mensaje (normalmente uno que falló). */
    public function reenviar(WhatsappMensaje $mensaje)
    {
        if (! WhatsAppCloud::config()) {
            return response()->json(['message' => 'El envío directo de WhatsApp no está activo en este taller.'], 422);
        }
        $nuevo = WhatsAppCloud::enviar($mensaje->telefono, $mensaje->texto);
        if ($nuevo->estado !== WhatsappMensaje::ENVIADO) {
            return response()->json(['message' => "No se envió a {$mensaje->telefono}: {$nuevo->error}"], 422);
        }

        return response()->json(['message' => "Mensaje reenviado a {$mensaje->telefono}."]);
    }

    // ------------------------------------------------------------------ envío

    /** Lo que antes abría WhatsApp, ahora se manda directo. Sin número va al dueño (el corte de caja). */
    public function enviar(Request $r)
    {
        $d = $r->validate([
            'numero' => ['nullable', 'string', 'max:30'],
            'texto' => ['required', 'string', 'max:4096'],
        ]);
        $config = WhatsAppCloud::config();
        if (! $config) {
            return response()->json(['message' => 'El envío directo de WhatsApp no está activo en este taller.'], 422);
        }
        $numero = WhatsApp::numero($d['numero'] ?? null) ?? WhatsApp::numero($config['dueno']);
        if (! $numero) {
            return response()->json(['message' => 'Este mensaje no tiene a quién mandarse. Captura el WhatsApp del dueño en Configuración → WhatsApp.'], 422);
        }

        $mensaje = WhatsAppCloud::enviar($numero, $d['texto']);
        if ($mensaje->estado !== WhatsappMensaje::ENVIADO) {
            return response()->json(['message' => "No se envió a {$numero}: {$mensaje->error}"], 422);
        }

        return response()->json(['message' => "Mensaje enviado por WhatsApp a {$numero}."]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\FotoOrden;
use App\Models\OrdenReparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Documentos;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Órdenes de servicio de clientes finales (mostrador). */
class OrdenReparacionController extends Controller
{
    private const RELACIONES = ['cliente', 'equipo', 'tecnico', 'fotos', 'pagos'];

    /** Reglas con los catálogos limitados al taller del usuario: no se puede apuntar a datos de otro taller. */
    private function camposComunes(Request $request): array
    {
        $tallerId = $request->user()->taller_id;

        return [
            'tecnico_id' => ['nullable', Rule::exists('users', 'id')->where('taller_id', $tallerId)],
            'sucursal_id' => ['nullable', Rule::exists('sucursales', 'id')->where('taller_id', $tallerId)],
            'prioridad' => ['nullable', 'in:baja,media,alta'],
            'tipo_servicio' => ['nullable', 'in:reparacion,diagnostico,garantia,mantenimiento'],
            'bloqueo_tipo' => ['nullable', 'in:ninguno,pin,patron,clave'],
            'bloqueo_valor' => ['nullable', 'string', 'max:100'],
            'bloqueo_pin' => ['nullable', 'string', 'max:100'],
            'bloqueos' => ['nullable', 'array'],
            'bloqueos.*' => ['string', 'distinct', 'in:operador,frp,icloud,mdm,knox'],
            'falla_reportada' => ['required', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'estado_fisico' => ['nullable', 'array'],
            'estado_fisico.*' => ['string', 'in:bueno,regular,danado,roto,falta'],
            'revision' => ['nullable', 'array'],
            'revision.*' => ['nullable', 'in:si,no'],
            'diagnostico' => ['nullable', 'string', 'max:2000'],
            'costo_estimado' => ['nullable', 'numeric', 'min:0'],
            // costos internos (solo el dueño): se descartan si el usuario no tiene "ordenes.ver_costos"
            'costo_refaccion' => ['nullable', 'numeric', 'min:0'],
            'costo_mano_obra' => ['nullable', 'numeric', 'min:0'],
            'anticipo' => ['nullable', 'numeric', 'min:0'],
            'metodo_pago' => ['nullable', 'in:efectivo,tarjeta,transferencia'],
            'estimado_listo_at' => ['nullable', 'date'],
            'notas' => ['nullable', 'string'],
            'notas_cliente' => ['nullable', 'string'],
            'visible_cliente' => ['nullable', 'boolean'],
            'visibilidad' => ['nullable', 'array'],
            'visibilidad.*' => ['string', 'distinct', 'in:fotos_antes,fotos_proceso,fotos_despues,motivo,fechas,diagnostico,costos'],
            // Lote de fotos subidas como borrador que se asocian a la orden al guardar.
            'lote' => ['nullable', 'uuid'],
        ];
    }

    /** Campos que se guardan tal cual, normalizando fecha y revisión. */
    private function camposOrden(array $datos): array
    {
        $campos = collect($datos)->except(['cliente_id', 'equipo_id', 'cliente_nuevo', 'equipo_nuevo', 'numero_orden', 'lote', 'anticipo'])->all();
        // sin permiso, los costos internos ni se leen ni se tocan (se quedan como estaban)
        if (! auth()->user()?->can('ordenes.ver_costos')) {
            $campos = array_diff_key($campos, array_flip(OrdenReparacion::COSTOS_INTERNOS));
        }

        if (array_key_exists('estimado_listo_at', $campos)) {
            // llega en UTC desde el navegador; se guarda en la hora del taller
            $listo = $campos['estimado_listo_at'] ? Carbon::parse($campos['estimado_listo_at'])->setTimezone(config('app.timezone')) : null;
            $campos['estimado_listo_at'] = $listo;
            $campos['fecha_entrega_estimada'] = $listo?->toDateString();
        }
        if (array_key_exists('revision', $campos)) {
            // solo se guardan los puntos de la revisión que se marcaron
            $campos['revision'] = array_filter(array_intersect_key($campos['revision'] ?? [], OrdenReparacion::REVISION));
        }

        return $campos;
    }

    public function tecnicos(Request $request)
    {
        $tecnicos = User::role(['Tecnico', 'Administrador'])
            ->where('taller_id', $request->user()->taller_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($tecnicos);
    }

    public function index(Request $request)
    {
        $ordenes = OrdenReparacion::query()
            ->with(['cliente', 'equipo', 'tecnico'])
            ->when($request->query('estado') === 'abiertas', fn ($q) => $q->abiertas())
            ->when(in_array($request->query('estado'), OrdenReparacion::ESTADOS, true), fn ($q) => $q->where('estado', $request->query('estado')))
            ->when($request->query('cliente_id'), fn ($q, $clienteId) => $q->where('cliente_id', $clienteId))
            ->when($request->query('tecnico_id'), fn ($q, $tecnicoId) => $q->where('tecnico_id', $tecnicoId))
            ->when($request->filled('q'), function ($q) use ($request) {
                $texto = '%'.$request->query('q').'%';
                $q->where(fn ($w) => $w->where('numero_orden', 'like', $texto)
                    ->orWhere('folio', ltrim($request->query('q'), '#'))
                    ->orWhereHas('equipo', fn ($e) => $e->where('modelo', 'like', $texto)->orWhere('marca', 'like', $texto)->orWhere('imei', 'like', $texto))
                    ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', $texto)->orWhere('apellido', 'like', $texto)->orWhere('telefono', 'like', $texto)));
            })
            ->orderByDesc('folio')
            ->paginate($request->integer('por_pagina', 50));

        return response()->json($ordenes);
    }

    public function store(Request $request)
    {
        $usuario = $request->user();
        $tallerId = $usuario->taller_id;

        $datos = $request->validate($this->camposComunes($request) + [
            'numero_orden' => ['nullable', 'string', 'max:50', 'unique:ordenes_reparacion,numero_orden'],
            'cliente_id' => ['required_without:cliente_nuevo', 'nullable', Rule::exists('clientes', 'id')->where('taller_id', $tallerId)],
            'cliente_nuevo' => ['required_without:cliente_id', 'nullable', 'array'],
            'cliente_nuevo.nombre' => ['required_with:cliente_nuevo', 'string', 'max:255'],
            'cliente_nuevo.apellido' => ['nullable', 'string', 'max:255'],
            'cliente_nuevo.telefono' => ['required_with:cliente_nuevo', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
            'cliente_nuevo.telefono2' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
            'cliente_nuevo.email' => ['nullable', 'email', 'max:255'],
            'equipo_id' => ['required_without:equipo_nuevo', 'nullable', Rule::exists('equipos', 'id')->where('taller_id', $tallerId)],
            'equipo_nuevo' => ['required_without:equipo_id', 'nullable', 'array'],
            'equipo_nuevo.tipo' => ['required_with:equipo_nuevo', 'string', 'max:50'],
            'equipo_nuevo.marca' => ['required_with:equipo_nuevo', 'string', 'max:100'],
            'equipo_nuevo.modelo' => ['required_with:equipo_nuevo', 'string', 'max:100'],
            'equipo_nuevo.imei' => ['nullable', 'string', 'max:100'],
            'equipo_nuevo.color' => ['nullable', 'string', 'max:50'],
            'equipo_nuevo.accesorios' => ['nullable', 'string', 'max:255'],
            'equipo_nuevo.condicion_inicial' => ['nullable', 'string'],
        ], [
            'cliente_nuevo.telefono.regex' => 'El teléfono del cliente debe tener al menos 10 dígitos.',
            'falla_reportada.required' => 'Escribe qué falla reporta el cliente.',
        ]);

        if (! empty($datos['cliente_nuevo']) && ! $usuario->can('clientes.crear')) {
            abort(403, 'No tienes permiso para registrar clientes nuevos.');
        }
        if (! empty($datos['equipo_nuevo']) && ! $usuario->can('equipos.crear')) {
            abort(403, 'No tienes permiso para registrar equipos nuevos.');
        }

        $orden = DB::transaction(function () use ($datos, $usuario) {
            $clienteId = $datos['cliente_id'] ?? $this->clienteDe($datos['cliente_nuevo'])->id;

            if (! empty($datos['equipo_nuevo'])) {
                $equipoId = Equipo::create($datos['equipo_nuevo'] + ['cliente_id' => $clienteId])->id;
            } else {
                $equipoId = $datos['equipo_id'];
                if (Equipo::findOrFail($equipoId)->cliente_id !== (int) $clienteId) {
                    throw ValidationException::withMessages(['equipo_id' => ['El equipo no pertenece al cliente seleccionado.']]);
                }
            }

            $orden = new OrdenReparacion($this->camposOrden($datos) + [
                'cliente_id' => $clienteId,
                'equipo_id' => $equipoId,
                'numero_orden' => $datos['numero_orden'] ?? null,
                'estado' => OrdenReparacion::RECIBIDO,
                'fecha_recepcion' => now(),
                'prioridad' => $datos['prioridad'] ?? 'media',
                'anticipo' => $datos['anticipo'] ?? 0,
                'user_id' => $usuario->id,
            ]);
            $orden->sucursal_id = $datos['sucursal_id'] ?? Sucursal::actualId();
            $orden->save();

            $this->asociarFotos($orden, $datos['lote'] ?? null);
            // el anticipo entra a la caja de hoy con su forma de pago
            $orden->cambiarPagado((float) ($datos['anticipo'] ?? 0), 'Anticipo', $this->metodo($datos['metodo_pago'] ?? null));

            return $orden;
        });

        return response()->json($this->conEnlaces($orden), 201);
    }

    /** Si ya existe un cliente con ese teléfono lo reutiliza (y actualiza su nombre); si no, lo crea. */
    private function clienteDe(array $nuevo): Cliente
    {
        $nuevo['telefono'] = preg_replace('/\D/', '', $nuevo['telefono']);
        $nuevo['telefono2'] = preg_replace('/\D/', '', (string) ($nuevo['telefono2'] ?? ''));
        $cliente = Cliente::where('telefono', $nuevo['telefono'])->first();
        if ($cliente) {
            $cliente->update(['nombre' => trim($nuevo['nombre']), 'apellido' => $nuevo['apellido'] ?? $cliente->apellido,
                'telefono2' => $nuevo['telefono2'] !== '' ? $nuevo['telefono2'] : $cliente->telefono2]);

            return $cliente;
        }

        return Cliente::create($nuevo);
    }

    private function metodo(?string $metodo): string
    {
        return in_array(strtoupper((string) $metodo), Venta::METODOS, true) ? strtoupper($metodo) : Venta::EFECTIVO;
    }

    public function show(OrdenReparacion $orden)
    {
        return response()->json($this->conEnlaces($orden));
    }

    /** La orden con todo lo que necesita su ficha: relaciones, PIN/patrón y enlaces de WhatsApp. */
    private function conEnlaces(OrdenReparacion $orden): array
    {
        $orden->load(self::RELACIONES);
        $orden->makeVisible(['bloqueo_valor', 'bloqueo_pin']);
        $costos = [];
        if (auth()->user()?->can('ordenes.ver_costos')) {
            $orden->makeVisible(OrdenReparacion::COSTOS_INTERNOS);
            $costos = ['ganancia' => $orden->ganancia];
        }

        return $orden->toArray() + $costos + [
            'estado_texto' => $orden->estado_texto,
            'enlaces' => [
                'publico' => WhatsApp::enlaceOrden($orden),
                'pdf_publico' => route('orden.pdf', $orden->token),
                'whatsapp_ticket' => WhatsApp::enlaceTicketOrden($orden),
                'whatsapp_estatus' => WhatsApp::avisoOrden($orden),
            ],
        ];
    }

    public function update(Request $request, OrdenReparacion $orden)
    {
        // si trae bloqueos es liberación: solo recibido, en proceso, listo o rechazado
        $bloqueos = $request->has('bloqueos') ? (array) $request->input('bloqueos') : $orden->bloqueos;
        $datos = $request->validate($this->camposComunes($request) + [
            'estado' => ['required', Rule::in(OrdenReparacion::estadosPara($bloqueos))],
            'motivo_rechazo' => ['nullable', 'required_if:estado,'.OrdenReparacion::RECHAZADO, 'string', 'max:255'],
            'solucion' => ['nullable', 'string', 'max:2000'],
            'costo_final' => ['nullable', 'numeric', 'min:0'],
        ], $this->mensajesEstado($bloqueos));

        $antes = $orden->estado;
        DB::transaction(function () use ($orden, $datos) {
            // lo pagado solo cambia con abonos: así cada peso queda registrado en la caja
            $campos = $this->camposOrden($datos);
            $campos['motivo_rechazo'] = $datos['estado'] === OrdenReparacion::RECHAZADO ? trim($datos['motivo_rechazo']) : null;
            $campos['fecha_entrega_real'] = $datos['estado'] === OrdenReparacion::ENTREGADO ? ($orden->fecha_entrega_real ?? now()) : null;
            $orden->update($campos);
            $this->asociarFotos($orden, $datos['lote'] ?? null);
        });

        return response()->json($this->conEnlaces($orden) + ['aviso' => $this->aviso($orden, $antes)]);
    }

    /** Cambiar estatus, abonar o liquidar desde la ficha de la orden. */
    public function accion(Request $request, OrdenReparacion $orden)
    {
        $usuario = $request->user();
        $aviso = null;

        switch ($request->input('accion')) {
            case 'estatus':
                abort_unless($usuario->can('ordenes.cambiar_estado') || $usuario->can('ordenes.editar'), 403);
                $d = $request->validate([
                    'estado' => ['required', Rule::in(OrdenReparacion::estadosPara($orden->bloqueos))],
                    'motivo' => ['nullable', 'required_if:estado,'.OrdenReparacion::RECHAZADO, 'string', 'max:255'],
                ], $this->mensajesEstado($orden->bloqueos, 'motivo'));
                $nuevo = $d['estado'];
                $antes = $orden->estado;
                $orden->update([
                    'estado' => $nuevo,
                    'motivo_rechazo' => $nuevo === OrdenReparacion::RECHAZADO ? trim($d['motivo']) : null,
                    'fecha_entrega_real' => $nuevo === OrdenReparacion::ENTREGADO ? now() : null,
                ]);
                $aviso = $this->aviso($orden, $antes);
                $mensaje = "Folio {$orden->folio}: ahora está {$orden->estado_texto}."
                    .($nuevo === OrdenReparacion::ENTREGADO && $orden->saldo > 0 ? ' Ojo: aún debe '.dinero($orden->saldo).'.' : '');
                break;
            case 'abono':
                abort_unless($usuario->can('ordenes.cobrar'), 403);
                $d = $request->validate(['monto' => ['required', 'numeric', 'gt:0'], 'metodo' => ['nullable', Rule::in(Venta::METODOS)]]);
                $orden->cambiarPagado((float) $orden->pagado + (float) $d['monto'], 'Abono', $d['metodo'] ?? Venta::EFECTIVO);
                $mensaje = 'Abono de '.dinero($d['monto']).'. Saldo: '.dinero($orden->saldo).'.';
                break;
            case 'liquidar':
                abort_unless($usuario->can('ordenes.cobrar'), 403);
                if ($orden->saldo <= 0) {
                    return response()->json(['message' => 'Esta orden no tiene saldo pendiente.'], 422);
                }
                $d = $request->validate(['metodo' => ['nullable', Rule::in(Venta::METODOS)]]);
                $saldo = $orden->saldo;
                $orden->cambiarPagado($orden->total, 'Liquidación', $d['metodo'] ?? Venta::EFECTIVO);
                $mensaje = 'Orden liquidada ('.dinero($saldo).').';
                break;
            default:
                abort(400);
        }

        return response()->json(['message' => $mensaje, 'aviso' => $aviso, 'orden' => $this->conEnlaces($orden)]);
    }

    /** Mensajes de error del estatus: dicen cuáles se permiten (liberación o reparación). */
    private function mensajesEstado(?array $bloqueos, string $campoMotivo = 'motivo_rechazo'): array
    {
        return [
            'estado.in' => OrdenReparacion::esLiberacion($bloqueos)
                ? 'Una liberación solo puede estar Recibido, En proceso, Listo o Rechazado.'
                : 'En proceso y Rechazado son solo para liberaciones (órdenes con bloqueo).',
            "{$campoMotivo}.required_if" => 'Escribe por qué se rechazó la liberación.',
        ];
    }

    /** Si acaba de quedar lista, no quedó o se rechazó, deja listo el botón para avisar por WhatsApp. */
    private function aviso(OrdenReparacion $o, ?string $antes): ?array
    {
        $avisables = [OrdenReparacion::LISTO, OrdenReparacion::NO_QUEDO, OrdenReparacion::RECHAZADO];
        if (! in_array($o->estado, $avisables, true) || $antes === $o->estado) {
            return null;
        }
        $o->loadMissing('cliente', 'equipo');
        $que = match ($o->estado) {
            OrdenReparacion::LISTO => 'ya está listo?',
            OrdenReparacion::RECHAZADO => 'no se pudo liberar?',
            default => 'no quedó?',
        };

        return [
            'texto' => "¿Avisar a {$o->cliente->nombre_completo} que su {$o->equipo?->nombre} {$que}",
            'url' => WhatsApp::avisoOrden($o),
            'sin_numero' => ! $o->cliente->telefono,
        ];
    }

    public function destroy(Request $request, OrdenReparacion $orden)
    {
        DB::transaction(function () use ($request, $orden) {
            if ($request->input('cobros') === 'quitar') {
                $orden->pagos()->delete(); // fue un error de captura
            }
            $orden->fotos->each->delete(); // también borra los archivos
            $orden->delete();
        });

        return response()->json(null, 204);
    }

    /** Datos del comprobante para imprimir (sin PIN ni patrón). */
    public function recibo(OrdenReparacion $orden)
    {
        $orden->load('cliente', 'equipo', 'sucursal', 'taller');

        return response()->json([
            'orden' => $orden->toArray() + ['estado_texto' => $orden->estado_texto, 'tiene_password' => (bool) ($orden->bloqueo_valor || $orden->bloqueo_pin)],
            'taller' => $orden->taller->only(['nombre', 'whatsapp']) + ['logo_url' => $orden->taller->logoUrl()],
            'revision_campos' => OrdenReparacion::REVISION,
            'terminos' => Ajuste::valor('terminos_orden', Ajuste::TERMINOS_ORDEN),
            'aviso' => Ajuste::valor('aviso_recoger', Ajuste::AVISO_RECOGER),
            'enlace' => WhatsApp::enlaceOrden($orden),
        ]);
    }

    /** PDF del comprobante (dentro del sistema). */
    public function pdf(OrdenReparacion $orden)
    {
        return Documentos::pdfOrden($orden)->stream(Documentos::nombreOrden($orden));
    }

    /** PDF del comprobante con el enlace que se manda por WhatsApp (sin iniciar sesión; no trae PIN ni patrón). */
    public function pdfPublico(string $token)
    {
        $orden = OrdenReparacion::withoutGlobalScopes()->where('token', $token)->firstOrFail();

        return Documentos::pdfOrden($orden)->stream(Documentos::nombreOrden($orden));
    }

    private function asociarFotos(OrdenReparacion $orden, ?string $lote): void
    {
        if (! $lote) {
            return;
        }

        FotoOrden::where('lote', $lote)
            ->whereNull('orden_reparacion_id')
            ->update(['orden_reparacion_id' => $orden->id, 'lote' => null]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\FotoOrden;
use App\Models\OrdenRefaccion;
use App\Models\OrdenReparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Documentos;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Órdenes de servicio de clientes finales (mostrador). */
class OrdenReparacionController extends Controller
{
    private const RELACIONES = ['cliente', 'equipo', 'tecnico', 'fotos', 'pagos', 'refacciones'];

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
            'costo_mano_externa' => ['nullable', 'numeric', 'min:0'],
            'mano_externa_detalle' => ['nullable', 'string', 'max:255'],
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
        $campos = collect($datos)->except(['cliente_id', 'equipo_id', 'cliente_nuevo', 'equipo_nuevo', 'numero_orden', 'lote', 'anticipo', 'metodo_pago', 'otros'])->all();
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
            ->when(in_array($request->query('estado'), ['entregadas', OrdenReparacion::ENTREGADO], true), fn ($q) => $q->whereNotNull('fecha_entrega_real'))
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

        // cuántos teléfonos trae cada orden, para marcar en la lista los que van juntos
        $grupos = collect($ordenes->items())->pluck('grupo')->filter()->unique();
        if ($grupos->isNotEmpty()) {
            $cuantos = OrdenReparacion::whereIn('grupo', $grupos)->selectRaw('grupo, COUNT(*) as n')->groupBy('grupo')->pluck('n', 'grupo');
            foreach ($ordenes->items() as $o) {
                $o->setAttribute('grupo_total', $o->grupo ? (int) ($cuantos[$o->grupo] ?? 1) : 1);
            }
        }

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
            // más teléfonos del mismo cliente en esta orden: cada uno con su falla, precio y bloqueos
            'otros' => ['nullable', 'array', 'max:20'],
            'otros.*.tipo' => ['nullable', 'string', 'max:50'],
            'otros.*.marca' => ['required', 'string', 'max:100'],
            'otros.*.modelo' => ['required', 'string', 'max:100'],
            'otros.*.imei' => ['nullable', 'string', 'max:100'],
            'otros.*.color' => ['nullable', 'string', 'max:50'],
            'otros.*.falla_reportada' => ['required', 'string', 'max:2000'],
            'otros.*.costo_estimado' => ['nullable', 'numeric', 'min:0'],
            'otros.*.bloqueo_tipo' => ['nullable', 'in:ninguno,pin,patron,clave'],
            'otros.*.bloqueo_valor' => ['nullable', 'string', 'max:100'],
            'otros.*.bloqueos' => ['nullable', 'array'],
            'otros.*.bloqueos.*' => ['string', 'distinct', 'in:operador,frp,icloud,mdm,knox'],
        ], [
            'cliente_nuevo.telefono.regex' => 'El teléfono del cliente debe tener al menos 10 dígitos.',
            'falla_reportada.required' => 'Escribe qué falla reporta el cliente.',
            'otros.*.marca.required' => 'Escribe la marca de cada teléfono que agregaste.',
            'otros.*.modelo.required' => 'Escribe el modelo de cada teléfono que agregaste.',
            'otros.*.falla_reportada.required' => 'Escribe la falla de cada teléfono que agregaste.',
        ]);

        if (! empty($datos['cliente_nuevo']) && ! $usuario->can('clientes.crear')) {
            abort(403, 'No tienes permiso para registrar clientes nuevos.');
        }
        if ((! empty($datos['equipo_nuevo']) || ! empty($datos['otros'])) && ! $usuario->can('equipos.crear')) {
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
                'user_id' => $usuario->id,
            ]);
            $orden->grupo = empty($datos['otros']) ? null : (string) Str::uuid();
            $orden->sucursal_id = $datos['sucursal_id'] ?? Sucursal::actualId();
            $orden->save();

            $this->asociarFotos($orden, $datos['lote'] ?? null);
            foreach ($datos['otros'] ?? [] as $otro) {
                $this->otroTelefono($orden, $otro);
            }

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

    /** Otro teléfono que el cliente deja en la misma orden: comparte cliente, técnico, sucursal y fecha prometida. */
    private function otroTelefono(OrdenReparacion $principal, array $otro): OrdenReparacion
    {
        $equipo = Equipo::create([
            'cliente_id' => $principal->cliente_id,
            'tipo' => $otro['tipo'] ?? 'celular',
            'marca' => $otro['marca'],
            'modelo' => $otro['modelo'],
            'imei' => $otro['imei'] ?? null,
            'color' => $otro['color'] ?? null,
        ]);
        $tipo = $otro['bloqueo_tipo'] ?? 'ninguno';

        // los vacíos no se copian: así toman el valor por defecto de la tabla
        $comunes = array_filter($principal->only([
            'cliente_id', 'tecnico_id', 'prioridad', 'tipo_servicio', 'estimado_listo_at', 'fecha_entrega_estimada',
            'notas_cliente', 'visible_cliente', 'visibilidad', 'user_id',
        ]), fn ($v) => $v !== null);
        $orden = new OrdenReparacion($comunes + [
            'equipo_id' => $equipo->id,
            'grupo' => $principal->grupo,
            'estado' => OrdenReparacion::RECIBIDO,
            'fecha_recepcion' => $principal->fecha_recepcion,
            'falla_reportada' => $otro['falla_reportada'],
            'costo_estimado' => $otro['costo_estimado'] ?? null,
            'bloqueos' => array_values($otro['bloqueos'] ?? []),
            'bloqueo_tipo' => $tipo,
            'bloqueo_valor' => $tipo === 'ninguno' ? null : ($otro['bloqueo_valor'] ?? null),
        ]);
        $orden->sucursal_id = $principal->sucursal_id;
        $orden->save();

        return $orden;
    }

    public function show(OrdenReparacion $orden)
    {
        return response()->json($this->conEnlaces($orden));
    }

    /** La orden con todo lo que necesita su ficha: relaciones, PIN/patrón y enlaces de WhatsApp. */
    public function conEnlaces(OrdenReparacion $orden): array
    {
        $orden->load(self::RELACIONES);
        $orden->makeVisible(['bloqueo_valor', 'bloqueo_pin']);
        $costos = [];
        if (auth()->user()?->can('ordenes.ver_costos')) {
            $orden->makeVisible(OrdenReparacion::COSTOS_INTERNOS);
            $orden->refacciones->each->makeVisible(OrdenRefaccion::COSTOS);
            $costos = ['ganancia' => $orden->ganancia];
        }

        return $orden->toArray() + $costos + [
            'estado_texto' => $orden->estado_texto,
            'grupo_ordenes' => $this->resumenGrupo($orden),
            'enlaces' => [
                'publico' => WhatsApp::enlaceOrden($orden),
                'pdf_publico' => route('orden.pdf', $orden->token),
                'whatsapp_ticket' => WhatsApp::enlaceTicketOrden($orden),
                'whatsapp_estatus' => WhatsApp::avisoOrden($orden),
            ],
        ];
    }

    /** Los teléfonos de la misma orden (vacío si va sola), para la ficha y el comprobante. */
    private function resumenGrupo(OrdenReparacion $orden): array
    {
        if (! $orden->grupo) {
            return [];
        }

        return $orden->delGrupo()->map(fn (OrdenReparacion $o) => [
            'id' => $o->id,
            'folio' => $o->folio,
            'equipo' => trim(($o->equipo?->nombre ?? '').' '.($o->equipo?->color ?? '')),
            'imei' => $o->equipo?->imei,
            'falla_reportada' => $o->falla_reportada,
            'estado' => $o->estado,
            'estado_texto' => $o->estado_texto,
            'es_liberacion' => $o->es_liberacion,
            'total' => $o->total,
            'pagado' => (float) $o->pagado,
            'saldo' => $o->saldo,
            'entregado' => $o->entregado,
        ])->values()->all();
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
        // al pasar a "No quedó" se pide el motivo (va en el aviso al cliente); las que ya estaban así lo conservan
        $motivo = trim((string) ($datos['motivo_rechazo'] ?? ''));
        if ($datos['estado'] === OrdenReparacion::NO_QUEDO && $motivo === '') {
            if ($antes !== OrdenReparacion::NO_QUEDO) {
                throw ValidationException::withMessages(['motivo_rechazo' => 'Escribe por qué no quedó.']);
            }
            $datos['motivo_rechazo'] = $orden->motivo_rechazo;
        }
        DB::transaction(function () use ($orden, $datos) {
            // lo pagado solo cambia con abonos: así cada peso queda registrado en la caja
            $campos = $this->camposOrden($datos);
            // con refacciones en la lista, su costo es la suma de ellas: no se captura a mano
            if ($orden->refacciones()->exists()) {
                unset($campos['costo_refaccion']);
            }
            $campos['motivo_rechazo'] = in_array($datos['estado'], [OrdenReparacion::RECHAZADO, OrdenReparacion::NO_QUEDO], true)
                ? (trim((string) $datos['motivo_rechazo']) ?: null) : null;
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
                    // rechazado (liberación) o no quedó: el motivo va en el aviso al cliente
                    'motivo' => ['nullable', 'required_if:estado,'.OrdenReparacion::RECHAZADO.','.OrdenReparacion::NO_QUEDO, 'string', 'max:255'],
                ], $this->mensajesEstado($orden->bloqueos, 'motivo'));
                $nuevo = $d['estado'];
                $antes = $orden->estado;
                $orden->update([
                    'estado' => $nuevo,
                    'motivo_rechazo' => in_array($nuevo, [OrdenReparacion::RECHAZADO, OrdenReparacion::NO_QUEDO], true) ? trim($d['motivo']) : null,
                ]);
                $aviso = $this->aviso($orden, $antes);
                $mensaje = "Folio {$orden->folio}: ahora está {$orden->estado_texto}.";
                break;
            case 'entregar':
            case 'quitar_entrega':
                abort_unless($usuario->can('ordenes.cambiar_estado') || $usuario->can('ordenes.editar'), 403);
                $entregar = $request->input('accion') === 'entregar';
                // "todo": todos los teléfonos de la orden que falten; si no, solo este
                $cuales = $request->boolean('todo') ? $orden->delGrupo() : collect([$orden]);
                $cuales = $cuales->filter(fn (OrdenReparacion $o) => $o->entregado !== $entregar);
                if ($cuales->isEmpty()) {
                    return response()->json(['message' => $entregar ? 'Ya estaba entregado.' : 'No estaba marcado como entregado.'], 422);
                }
                DB::transaction(fn () => $cuales->each(fn (OrdenReparacion $o) => $o->update(['fecha_entrega_real' => $entregar ? now() : null])));
                $folios = $cuales->pluck('folio')->implode(', ');
                $debe = $cuales->sum(fn (OrdenReparacion $o) => max(0, $o->saldo));
                $mensaje = $entregar
                    ? ($cuales->count() > 1 ? "Folios {$folios}: entregados." : "Folio {$folios}: entregado.")
                        .($debe > 0 ? ' Ojo: aún debe '.dinero($debe).'.' : '')
                    : "Folio {$folios}: ya no está marcado como entregado.";
                $orden->refresh();
                break;
            case 'abono':
                abort_unless($usuario->can('ordenes.cobrar'), 403);
                $d = $request->validate(['monto' => ['required', 'numeric', 'gt:0'], 'metodo' => ['nullable', Rule::in(Venta::METODOS)]]);
                if ($request->boolean('todo') && $orden->grupo) {
                    // un abono para toda la orden: va llenando lo que debe cada teléfono; lo que sobre, a este
                    $resta = (float) $d['monto'];
                    DB::transaction(function () use ($orden, &$resta, $d) {
                        foreach ($orden->delGrupo() as $o) {
                            $parte = min($resta, max(0, $o->saldo));
                            if ($parte > 0) {
                                $o->cambiarPagado((float) $o->pagado + $parte, 'Abono', $d['metodo'] ?? Venta::EFECTIVO);
                                $resta = round($resta - $parte, 2);
                            }
                        }
                        if ($resta > 0) {
                            $orden->refresh()->cambiarPagado((float) $orden->pagado + $resta, 'Abono', $d['metodo'] ?? Venta::EFECTIVO);
                        }
                    });
                    $orden->refresh();
                    $mensaje = 'Abono de '.dinero($d['monto']).' a toda la orden. Resta: '.dinero(max(0, $orden->delGrupo()->sum('saldo'))).'.';
                    break;
                }
                $orden->cambiarPagado((float) $orden->pagado + (float) $d['monto'], 'Abono', $d['metodo'] ?? Venta::EFECTIVO);
                $mensaje = 'Abono de '.dinero($d['monto']).'. Saldo: '.dinero($orden->saldo).'.';
                break;
            case 'liquidar':
                abort_unless($usuario->can('ordenes.cobrar'), 403);
                $d = $request->validate(['metodo' => ['nullable', Rule::in(Venta::METODOS)]]);
                $cuales = ($request->boolean('todo') ? $orden->delGrupo() : collect([$orden]))->filter(fn (OrdenReparacion $o) => $o->saldo > 0);
                if ($cuales->isEmpty()) {
                    return response()->json(['message' => 'Esta orden no tiene saldo pendiente.'], 422);
                }
                $saldo = $cuales->sum('saldo');
                DB::transaction(fn () => $cuales->each(fn (OrdenReparacion $o) => $o->cambiarPagado($o->total, 'Liquidación', $d['metodo'] ?? Venta::EFECTIVO)));
                $orden->refresh();
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
            "{$campoMotivo}.required_if" => 'Escribe el motivo: por qué no quedó o por qué se rechazó.',
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
            // las piezas del inventario que se habían usado regresan
            foreach ($orden->refacciones as $refaccion) {   // foreach: ->each se detendría en la primera comprada fuera
                $refaccion->regresarAlInventario("Se eliminó la orden N° {$orden->folio}");
            }
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
            'grupo' => $this->resumenGrupo($orden),
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

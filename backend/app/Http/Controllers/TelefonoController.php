<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Locatario;
use App\Models\Telefono;
use App\Support\WhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Teléfonos de locatarios (mayoreo). */
class TelefonoController extends Controller
{
    /** Aplica los filtros de la pantalla (también los usa Exportar). */
    protected function filtrar(Request $r): Builder
    {
        $q = Telefono::query()->with('locatario', 'taller');
        if ($r->filled('locatario')) {
            $q->where('locatario_id', $r->integer('locatario'));
        }
        match ($r->input('estado')) {
            'taller' => $q->where('resultado', Telefono::EN_TALLER)->where('entregado', false),
            'listos' => $q->where('resultado', Telefono::LISTO)->where('entregado', false),
            'noquedo' => $q->where('resultado', Telefono::NO_QUEDO)->where('entregado', false),
            'saldo' => $q->conSaldo(),
            'entregados_deben' => $q->where('entregado', true)->conSaldo(),
            'entregados_pagados' => $q->where('entregado', true)->sinSaldo(),
            default => null,
        };
        if (in_array($r->input('prioridad'), Telefono::PRIORIDADES, true)) {
            $q->where('prioridad', $r->input('prioridad'));
        }
        if ($r->filled('q')) {
            $texto = '%'.$r->input('q').'%';
            $q->where(fn ($w) => $w->where('modelo', 'like', $texto)->orWhere('trabajo', 'like', $texto)
                ->orWhere('notas', 'like', $texto)
                ->orWhereHas('locatario', fn ($l) => $l->where('nombre', 'like', $texto)));
        }
        if ($r->boolean('urgentes')) {
            $q->orderByRaw("CASE prioridad WHEN 'ALTA' THEN 1 WHEN 'MEDIA' THEN 2 ELSE 3 END")->orderBy('id');
        } else {
            $q->orderByDesc('id');
        }

        return $q;
    }

    /** El teléfono como lo necesita la tabla: con saldo y enlace para avisar por WhatsApp. */
    private function formato(Telefono $t): array
    {
        $costos = [];
        if (auth()->user()?->can('ordenes.ver_costos')) {
            $t->makeVisible('costo');
            $costos = ['ganancia' => $t->ganancia];
        }

        return $t->toArray() + $costos + ['saldo' => $t->saldo, 'estado_pago' => $t->estado_pago, 'aviso_url' => WhatsApp::aviso($t)];
    }

    public function index(Request $r)
    {
        $q = $this->filtrar($r);

        $noQuedo = Telefono::NO_QUEDO;
        $resumen = (clone $q)->reorder()->toBase()->selectRaw("
            COUNT(*) as telefonos,
            SUM(CASE WHEN entregado = 0 AND resultado = 'EN TALLER' THEN 1 ELSE 0 END) as en_taller,
            SUM(CASE WHEN entregado = 0 AND resultado = 'LISTO' THEN 1 ELSE 0 END) as listos,
            SUM(CASE WHEN entregado = 0 AND resultado = ? THEN 1 ELSE 0 END) as no_quedaron,
            SUM(CASE WHEN resultado <> ? THEN total ELSE 0 END) as total,
            SUM(pagado) as cobrado,
            SUM(CASE WHEN resultado <> ? AND total > pagado THEN total - pagado ELSE 0 END) as por_cobrar,
            COALESCE(SUM(costo), 0) as gasto
        ", [$noQuedo, $noQuedo, $noQuedo])->first();
        // gasto y ganancia solo para el dueño
        if ($r->user()->can('ordenes.ver_costos')) {
            $resumen->ganancia = round((float) $resumen->total - (float) $resumen->gasto, 2);
        } else {
            unset($resumen->gasto);
        }

        $pagina = $q->paginate($r->integer('por_pagina', 50));
        $pagina->getCollection()->transform(fn (Telefono $t) => $this->formato($t));

        return response()->json($pagina->toArray() + [
            'resumen' => $resumen,
            'locatarios' => Locatario::orderBy('nombre')->get(['id', 'nombre', 'whatsapp']),
        ]);
    }

    public function show(Telefono $telefono)
    {
        $telefono->load('locatario', 'pagos');

        return response()->json($this->formato($telefono) + ['cobros_total' => (float) $telefono->pagos->sum('monto')]);
    }

    protected function validar(Request $r): array
    {
        return $r->validate([
            'locatario' => ['required', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
            'prioridad' => ['required', Rule::in(Telefono::PRIORIDADES)],
            'fecha_recibido' => ['required', 'date'],
            'modelo' => ['required', 'string', 'max:255'],
            'trabajo' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'min:0'],
            // gasto (créditos, servidor o pieza): solo el dueño; sin permiso se ignora
            'costo' => ['nullable', 'numeric', 'min:0'],
            'pagado' => ['required', 'numeric', 'min:0'],
            'resultado' => ['required', Rule::in(Telefono::RESULTADOS)],
            'entregado' => ['nullable', 'boolean'],
        ], ['whatsapp.regex' => 'El WhatsApp debe tener al menos 10 dígitos.']);
    }

    /** Guarda los datos del formulario; devuelve el estatus que tenía antes. */
    protected function guardar(Request $r, Telefono $t): ?string
    {
        $d = $this->validar($r);
        $antes = $t->exists ? $t->resultado : null;
        $nuevo = ! $t->exists;

        DB::transaction(function () use ($r, $d, $t, $nuevo) {
            $loc = Locatario::porNombre($d['locatario']);
            $whats = preg_replace('/\D/', '', (string) ($d['whatsapp'] ?? ''));
            if ($whats !== ($loc->whatsapp ?? '')) {
                $loc->update(['whatsapp' => $whats === '' ? null : $whats]);
            }
            $entregado = $r->boolean('entregado');
            $t->fill([
                'locatario_id' => $loc->id,
                'prioridad' => $d['prioridad'],
                'fecha_recibido' => $d['fecha_recibido'],
                'modelo' => trim($d['modelo']),
                'trabajo' => trim($d['trabajo'] ?? ''),
                'notas' => trim($d['notas'] ?? ''),
                'total' => $d['total'],
                'resultado' => $d['resultado'],
                'fecha_entrega' => $entregado ? ($t->entregado ? $t->fecha_entrega : now()) : null,
                'entregado' => $entregado,
            ]);
            if ($r->user()->can('ordenes.ver_costos') && $r->has('costo')) {
                $t->costo = $d['costo'];
            }
            if ($nuevo) {
                $t->user_id = auth()->id();
                $t->pagado = 0;
            }
            $t->save();
            $t->setRelation('locatario', $loc);
            $t->cambiarPagado((float) $d['pagado'], $nuevo ? 'Anticipo' : 'Ajuste de pago');
        });

        return $antes;
    }

    public function store(Request $r)
    {
        $t = new Telefono;
        $this->guardar($r, $t);

        return response()->json([
            'message' => "Guardado teléfono #{$t->id} ({$t->modelo}).",
            'telefono' => $this->formato($t),
            'aviso' => $this->avisoSiQuedoListo($t, null),
        ], 201);
    }

    public function update(Request $r, Telefono $telefono)
    {
        $antes = $this->guardar($r, $telefono);

        return response()->json([
            'message' => "Cambios guardados en #{$telefono->id}.",
            'telefono' => $this->formato($telefono),
            'aviso' => $this->avisoSiQuedoListo($telefono, $antes),
        ]);
    }

    public function destroy(Request $r, Telefono $telefono)
    {
        DB::transaction(function () use ($r, $telefono) {
            if ($r->input('cobros') === 'quitar') {
                $telefono->pagos()->delete(); // fue un error de captura
            }
            // el saldo a favor que se usó en él regresa al locatario
            Credito::where('telefono_id', $telefono->id)->where('tipo', Credito::APLICADO)->delete();
            $telefono->delete();
        });

        return response()->json(null, 204);
    }

    /** Botones rápidos de la tabla: listo, no quedó, entregado, abono, liquidar. */
    public function accion(Request $r, Telefono $telefono)
    {
        $t = $telefono;
        $aviso = null;
        $accion = $r->input('accion');
        if (in_array($accion, ['abono', 'liquidar'], true)) {
            abort_unless($r->user()->can('telefonos.cobrar'), 403);
        } else {
            abort_unless($r->user()->can('telefonos.editar'), 403);
        }

        switch ($accion) {
            case 'listo':
            case 'noquedo':
                $estatus = $accion === 'listo' ? Telefono::LISTO : Telefono::NO_QUEDO;
                $antes = $t->resultado;
                // presionar el mismo estatus otra vez lo regresa a "En taller"
                $t->update(['resultado' => $antes === $estatus ? Telefono::EN_TALLER : $estatus]);
                $mensaje = "#{$t->id} {$t->modelo}: ahora está {$t->resultado}.";
                $aviso = $this->avisoSiQuedoListo($t, $antes);
                break;
            case 'entregado':
                if ($t->entregado) {
                    $t->update(['entregado' => false, 'fecha_entrega' => null]);
                    $mensaje = "#{$t->id}: se le quitó la entrega.";
                } else {
                    $t->update(['entregado' => true, 'fecha_entrega' => now()]);
                    $mensaje = "#{$t->id} entregado.".($t->saldo > 0 ? ' Ojo: aún debe '.dinero($t->saldo).'.' : '');
                }
                break;
            case 'abono':
                $monto = (float) $r->validate(['monto' => ['required', 'numeric', 'gt:0']])['monto'];
                $t->cambiarPagado((float) $t->pagado + $monto, 'Abono');
                $mensaje = 'Abono de '.dinero($monto)." a #{$t->id}. Saldo: ".dinero($t->saldo).'.';
                break;
            case 'liquidar':
                if ($t->resultado === Telefono::NO_QUEDO) {
                    return response()->json(['message' => 'Este teléfono no quedó: no se cobra.'], 422);
                }
                if ($t->saldo <= 0) {
                    return response()->json(['message' => 'Este teléfono ya está pagado.'], 422);
                }
                $saldo = $t->saldo;
                $t->cambiarPagado((float) $t->total, 'Liquidación');
                $mensaje = "#{$t->id} liquidado (".dinero($saldo).').';
                break;
            default:
                abort(400);
        }

        return response()->json(['message' => $mensaje, 'aviso' => $aviso, 'telefono' => $this->formato($t->fresh(['locatario', 'taller']))]);
    }

    /** Si acaba de pasar a LISTO, deja listo el botón para avisar por WhatsApp. */
    protected function avisoSiQuedoListo(Telefono $t, ?string $antes): ?array
    {
        if ($t->resultado !== Telefono::LISTO || $antes === Telefono::LISTO) {
            return null;
        }
        $t->loadMissing('locatario');

        return [
            'texto' => "¿Avisar a {$t->locatario->nombre} que su {$t->modelo} ya está listo?",
            'url' => WhatsApp::aviso($t),
            'sin_numero' => ! $t->locatario->whatsapp,
        ];
    }

    public function exportar(Request $r): StreamedResponse
    {
        $q = $this->filtrar($r);
        $costos = $r->user()->can('ordenes.ver_costos');   // gasto y ganancia solo para el dueño

        return response()->streamDownload(function () use ($q, $costos) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // para que Excel lea bien los acentos
            fputcsv($out, array_merge(['Id', 'Prioridad', 'Recibido', 'Locatario', 'Modelo', 'Trabajo', 'Notas', 'Total', 'Pagado',
                'Saldo', 'Pago', 'Estatus', 'Entrega', 'Entregado el'], $costos ? ['Gasto', 'Ganancia'] : []));
            foreach ($q->cursor() as $t) {
                fputcsv($out, array_merge([$t->id, $t->prioridad, $t->fecha_recibido->format('d/m/Y'), $t->locatario->nombre,
                    $t->modelo, $t->trabajo, $t->notas, $t->total, $t->pagado, number_format($t->saldo, 2, '.', ''),
                    $t->estado_pago, $t->resultado, $t->entregado ? 'ENTREGADO' : 'POR ENTREGAR',
                    $t->fecha_entrega?->format('d/m/Y H:i')], $costos ? [$t->costo, number_format($t->ganancia, 2, '.', '')] : []));
            }
            fclose($out);
        }, 'telefonos_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

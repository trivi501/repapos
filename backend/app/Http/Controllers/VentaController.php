<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Locatario;
use App\Models\Producto;
use App\Models\Venta;
use App\Support\Documentos;
use App\Support\Inventario;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Punto de venta: venta de mostrador de productos del inventario. */
class VentaController extends Controller
{
    public function index(Request $r)
    {
        $desde = $r->filled('desde') ? Carbon::parse($r->input('desde'))->startOfDay() : today();
        $hasta = $r->filled('hasta') ? Carbon::parse($r->input('hasta'))->startOfDay() : today();
        if ($hasta->lt($desde)) {
            $hasta = $desde->copy();
        }
        $ventas = Venta::with('detalles', 'user')
            // "a crédito con saldo": todas las que deben, sin importar la fecha
            ->when($r->boolean('credito'), fn ($q) => $q->conSaldo(),
                fn ($q) => $q->where('created_at', '>=', $desde)->where('created_at', '<', $hasta->copy()->addDay()))
            ->orderByDesc('id')->get();
        $pagadas = $ventas->where('estatus', '!=', Venta::CANCELADA);

        return response()->json([
            'ventas' => $ventas->map(fn (Venta $v) => $this->formato($v))->values(),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'resumen' => [
                'ventas' => $pagadas->count(),
                'total' => $pagadas->sum(fn ($v) => $v->neto),
                'por_cobrar' => $pagadas->sum(fn ($v) => $v->saldo),
                'ganancia' => $pagadas->sum(fn ($v) => $v->ganancia),
                'cliente' => $pagadas->where('tipo', Venta::CLIENTE)->sum(fn ($v) => $v->neto),
                'locatario' => $pagadas->where('tipo', Venta::LOCATARIO)->sum(fn ($v) => $v->neto),
                'metodos' => collect([...Venta::METODOS, Venta::CREDITO])->mapWithKeys(fn ($m) => [$m => $pagadas->where('metodo', $m)->sum(fn ($v) => $v->neto)]),
            ],
        ]);
    }

    private function formato(Venta $v): array
    {
        return $v->toArray() + ['neto' => $v->neto, 'saldo' => $v->saldo, 'cambio' => $v->cambio];
    }

    /** Catálogo con la existencia de la sucursal actual, para buscar sin volver a preguntar al servidor. */
    public function catalogo()
    {
        $productos = Producto::with('existencia')->orderBy('nombre')->get()->map(fn ($p) => [
            'id' => $p->id, 'nombre' => $p->nombre, 'codigo' => $p->codigo, 'categoria' => $p->categoria,
            'precio' => $p->precioPara(Venta::CLIENTE), 'precio_locatario' => $p->precioPara(Venta::LOCATARIO),
            'existencia' => $p->existencia->cantidad ?? 0,
        ]);

        return response()->json(['productos' => $productos, 'locatarios' => Locatario::orderBy('nombre')->pluck('nombre')]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:10000'],
            'lineas.*.precio' => ['required', 'numeric', 'min:0'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'metodo' => ['required', Rule::in([...Venta::METODOS, Venta::CREDITO])],
            'tipo' => ['nullable', Rule::in([Venta::CLIENTE, Venta::LOCATARIO])],
            'locatario' => ['required_if:tipo,'.Venta::LOCATARIO, 'nullable', 'string', 'max:255'],
            'recibido' => ['nullable', 'numeric', 'min:0'],
            'cliente_nombre' => ['nullable', 'string', 'max:120'],
            'cliente_telefono' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
        ], [
            'lineas.required' => 'Agrega al menos un producto.',
            'locatario.required_if' => 'Elige a qué locatario se le vende.',
            'cliente_telefono.regex' => 'El teléfono del cliente debe tener al menos 10 dígitos.',
        ]);

        // solo productos del catálogo de este taller
        $productos = Producto::whereIn('id', collect($d['lineas'])->pluck('producto_id'))->get()->keyBy('id');
        $lineas = [];
        foreach ($d['lineas'] as $l) {
            $p = $productos->get($l['producto_id']);
            abort_unless($p, 404);
            $lineas[] = ['producto' => $p, 'cantidad' => (int) $l['cantidad'], 'precio' => (float) $l['precio']];
        }

        // venta a locatario: tiene que ser uno de la lista del taller
        $locatario = null;
        if (($d['tipo'] ?? Venta::CLIENTE) === Venta::LOCATARIO) {
            $locatario = Locatario::whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($d['locatario']))])->first();
            if (! $locatario) {
                return response()->json(['message' => "No existe el locatario \"{$d['locatario']}\". Elígelo de la lista."], 422);
            }
        }

        try {
            $venta = Inventario::vender($lineas, (float) ($d['descuento'] ?? 0), $d['metodo'],
                isset($d['recibido']) && $d['recibido'] !== '' ? (float) $d['recibido'] : null,
                $d['cliente_nombre'] ?? '', $d['cliente_telefono'] ?? '', $locatario);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $mensaje = $venta->metodo === Venta::CREDITO
            ? "Venta {$venta->folio} a crédito a {$venta->cliente_nombre}: debe ".dinero($venta->saldo).'.'
            : "Venta {$venta->folio} cobrada: ".dinero($venta->total).($venta->cambio > 0 ? '. Cambio: '.dinero($venta->cambio) : '').'.';

        return response()->json(['message' => $mensaje, 'venta' => ['id' => $venta->id]], 201);
    }

    public function show(Venta $venta)
    {
        $venta->load('detalles', 'user', 'pagos');

        return response()->json($this->formato($venta) + [
            'ganancia' => $venta->ganancia,
            'whatsapp' => WhatsApp::enlaceTicketVenta($venta),
            'pdf_publico' => route('venta.pdf', $venta->token),
        ]);
    }

    /** Datos del ticket para impresora de 58/80 mm o carta. */
    public function ticket(Venta $venta)
    {
        $venta->load('detalles', 'sucursal', 'taller', 'user');

        return response()->json([
            'venta' => $this->formato($venta),
            'taller' => $venta->taller->only(['nombre', 'whatsapp']) + ['logo_url' => $venta->taller->logoUrl()],
            'pie' => Ajuste::valor('pie_ticket_venta', Ajuste::PIE_TICKET_VENTA),
        ]);
    }

    public function pdf(Venta $venta)
    {
        return Documentos::pdfVenta($venta)->stream(Documentos::nombreVenta($venta));
    }

    /** PDF del ticket con el enlace que se manda por WhatsApp (sin iniciar sesión). */
    public function pdfPublico(string $token)
    {
        $venta = Venta::withoutGlobalScopes()->where('token', $token)->firstOrFail();

        return Documentos::pdfVenta($venta)->stream(Documentos::nombreVenta($venta));
    }

    public function abonar(Request $r, Venta $venta)
    {
        $d = $r->validate(['monto' => ['required', 'numeric', 'gt:0'], 'metodo' => ['required', Rule::in(Venta::METODOS)]]);
        try {
            Inventario::abonarVenta($venta, (float) $d['monto'], $d['metodo']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $venta->refresh();

        return response()->json(['message' => 'Abono de '.dinero($d['monto']).' a la venta '.$venta->folio.'. '
            .($venta->saldo > 0 ? 'Resta '.dinero($venta->saldo).'.' : 'Quedó pagada.')]);
    }

    public function devolver(Request $r, Venta $venta)
    {
        $d = $r->validate([
            'piezas' => ['required', 'array'],
            'piezas.*' => ['nullable', 'integer', 'min:0'],
            'destino' => ['nullable', Rule::in(['efectivo', 'saldo_favor'])],
            'nota' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            $res = Inventario::devolver($venta, $d['piezas'], $d['destino'] ?? 'efectivo', trim($d['nota'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $msg = 'Devolución registrada por '.dinero($res['valor']).'; las piezas regresaron al inventario.';
        $msg .= match ($res['destino']) {
            'efectivo' => ' Se le devuelven '.dinero($res['reembolso']).' en efectivo (salen de la caja).',
            'saldo_favor' => ' Quedan '.dinero($res['reembolso']).' como su saldo a favor.',
            default => $venta->fresh()->saldo > 0 ? ' Ahora debe '.dinero($venta->fresh()->saldo).'.' : '',
        };

        return response()->json(['message' => $msg]);
    }

    public function cancelar(Venta $venta)
    {
        $pagado = (float) $venta->pagado;
        try {
            Inventario::cancelar($venta);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Venta {$venta->folio} cancelada: las piezas regresaron al inventario y se restaron ".dinero($pagado).' de la caja.']);
    }
}

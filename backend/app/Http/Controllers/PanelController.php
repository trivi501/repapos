<?php

namespace App\Http\Controllers;

use App\Models\Locatario;
use App\Models\OrdenReparacion;
use App\Models\Pago;
use App\Models\Salida;
use App\Models\Telefono;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Datos de las gráficas del panel. Cada gráfica tiene su permiso: solo se calcula y se entrega lo que el
 * usuario puede ver. Los modelos filtran solos por taller y sucursal.
 */
class PanelController extends Controller
{
    public const GRAFICAS = ['panel.ingresos', 'panel.ventas', 'panel.reparaciones', 'panel.telefonos', 'panel.cobranza'];

    private const MAXIMO_FILAS = 8;

    public function index(Request $r)
    {
        $dias = (int) ($r->validate(['dias' => ['nullable', Rule::in([7, 30, 90])]])['dias'] ?? 30);
        $desde = today()->subDays($dias - 1);
        $hasta = today()->addDay();   // exclusivo
        $calendario = collect(range(0, $dias - 1))->map(fn ($i) => $desde->copy()->addDays($i)->toDateString());

        $respuesta = ['dias' => $dias, 'fechas' => $calendario];
        $usuario = $r->user();

        if ($usuario->can('panel.ingresos')) {
            $respuesta['ingresos'] = $this->ingresos($desde, $hasta, $calendario);
        }
        if ($usuario->can('panel.ventas')) {
            $respuesta['ventas'] = $this->ventas($desde, $hasta, $calendario);
        }
        if ($usuario->can('panel.reparaciones')) {
            $respuesta['reparaciones'] = $this->reparaciones($desde, $hasta);
        }
        if ($usuario->can('panel.telefonos')) {
            $respuesta['telefonos'] = $this->telefonos($desde, $hasta, $calendario);
        }
        if ($usuario->can('panel.cobranza')) {
            $respuesta['cobranza'] = $this->cobranza();
        }

        return response()->json($respuesta);
    }

    private function ingresos(CarbonInterface $desde, CarbonInterface $hasta, Collection $calendario): array
    {
        $cobros = fn () => Pago::where('fecha', '>=', $desde)->where('fecha', '<', $hasta);
        $cobrado = $cobros()->selectRaw('DATE(fecha) as dia, SUM(monto) as total')->groupBy('dia')->pluck('total', 'dia');
        $salidas = Salida::where('fecha', '>=', $desde)->where('fecha', '<', $hasta)
            ->selectRaw('DATE(fecha) as dia, SUM(monto) as total')->groupBy('dia')->pluck('total', 'dia');

        $metodos = $cobros()->selectRaw('metodo, SUM(monto) as total')->groupBy('metodo')->orderByDesc('total')->get()
            ->filter(fn ($m) => (float) $m->total > 0)
            ->map(fn ($m) => ['nombre' => $m->metodo ?: 'Sin indicar', 'valor' => round((float) $m->total, 2)])
            ->values();

        return [
            'cobrado' => $this->porDia($calendario, $cobrado),
            'salidas' => $this->porDia($calendario, $salidas),
            'total_cobrado' => round((float) $cobrado->sum(), 2),
            'total_salidas' => round((float) $salidas->sum(), 2),
            'metodos' => $metodos,
        ];
    }

    private function ventas(CarbonInterface $desde, CarbonInterface $hasta, Collection $calendario): array
    {
        $ventas = fn () => Venta::where('estatus', '!=', Venta::CANCELADA)->where('created_at', '>=', $desde)->where('created_at', '<', $hasta);
        $porDia = $ventas()->selectRaw('DATE(created_at) as dia, SUM(total - devuelto) as total')->groupBy('dia')->pluck('total', 'dia');

        $productos = VentaDetalle::whereIn('venta_id', $ventas()->select('ventas.id'))
            ->selectRaw('nombre, SUM(cantidad - devuelto) as piezas')
            ->groupBy('nombre')->havingRaw('SUM(cantidad - devuelto) > 0')
            ->orderByDesc('piezas')->limit(self::MAXIMO_FILAS)->get()
            ->map(fn ($p) => ['nombre' => $p->nombre, 'valor' => (int) $p->piezas]);

        return [
            'importes' => $this->porDia($calendario, $porDia),
            'total' => round((float) $porDia->sum(), 2),
            'numero' => $ventas()->count(),
            'productos' => $productos,
        ];
    }

    private function reparaciones(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $abiertas = OrdenReparacion::whereNotIn('estado', [OrdenReparacion::ENTREGADO, OrdenReparacion::CANCELADO])
            ->selectRaw('estado, COUNT(*) as n')->groupBy('estado')->pluck('n', 'estado');

        return [
            'estados' => collect(OrdenReparacion::ESTADOS)
                ->filter(fn ($e) => $abiertas->has($e))
                ->map(fn ($e) => ['estado' => $e, 'valor' => (int) $abiertas[$e]])
                ->values(),
            'abiertas' => (int) $abiertas->sum(),
            'recibidas' => OrdenReparacion::where('fecha_recepcion', '>=', $desde)->where('fecha_recepcion', '<', $hasta)->count(),
            'entregadas' => OrdenReparacion::where('estado', OrdenReparacion::ENTREGADO)
                ->where('fecha_entrega_real', '>=', $desde)->where('fecha_entrega_real', '<', $hasta)->count(),
        ];
    }

    private function telefonos(CarbonInterface $desde, CarbonInterface $hasta, Collection $calendario): array
    {
        $recibidos = Telefono::where('fecha_recibido', '>=', $desde)->where('fecha_recibido', '<', $hasta)
            ->selectRaw('DATE(fecha_recibido) as dia, COUNT(*) as total')->groupBy('dia')->pluck('total', 'dia');
        $entregados = Telefono::where('entregado', true)->where('fecha_entrega', '>=', $desde)->where('fecha_entrega', '<', $hasta)
            ->selectRaw('DATE(fecha_entrega) as dia, COUNT(*) as total')->groupBy('dia')->pluck('total', 'dia');
        $pendientes = Telefono::where('entregado', false)->selectRaw('resultado, COUNT(*) as n')->groupBy('resultado')->pluck('n', 'resultado');

        return [
            'recibidos' => $this->porDia($calendario, $recibidos),
            'entregados' => $this->porDia($calendario, $entregados),
            'en_taller' => (int) ($pendientes[Telefono::EN_TALLER] ?? 0),
            'listos' => (int) ($pendientes[Telefono::LISTO] ?? 0),
            'no_quedaron' => (int) ($pendientes[Telefono::NO_QUEDO] ?? 0),
        ];
    }

    /** Lo mismo que suma el resumen por locatario: teléfonos con saldo y ventas a crédito. */
    private function cobranza(): array
    {
        $deudas = Telefono::conSaldo()->selectRaw('locatario_id, SUM(total - pagado) as debe')->groupBy('locatario_id')->pluck('debe', 'locatario_id');
        $ventas = Venta::conSaldo()->whereNotNull('locatario_id')->whereRaw('total - devuelto > pagado')
            ->selectRaw('locatario_id, SUM(total - devuelto - pagado) as debe')->groupBy('locatario_id')->pluck('debe', 'locatario_id');

        $porLocatario = $deudas->keys()->merge($ventas->keys())->unique()
            ->mapWithKeys(fn ($id) => [$id => round((float) ($deudas[$id] ?? 0) + (float) ($ventas[$id] ?? 0), 2)])
            ->sortDesc();

        $nombres = Locatario::whereIn('id', $porLocatario->keys()->take(self::MAXIMO_FILAS))->pluck('nombre', 'id');
        $filas = $porLocatario->take(self::MAXIMO_FILAS)
            ->map(fn ($debe, $id) => ['nombre' => $nombres[$id] ?? 'Sin nombre', 'valor' => $debe])
            ->values();

        $resto = $porLocatario->skip(self::MAXIMO_FILAS);
        if ($resto->isNotEmpty()) {
            $filas->push(['nombre' => "Otros ({$resto->count()})", 'valor' => round((float) $resto->sum(), 2)]);
        }

        return ['total' => round((float) $porLocatario->sum(), 2), 'locatarios' => $filas];
    }

    /** Un valor por cada día del periodo, con cero en los días sin movimientos. */
    private function porDia(Collection $calendario, Collection $valores): array
    {
        return $calendario->map(fn ($dia) => round((float) ($valores[$dia] ?? 0), 2))->all();
    }
}

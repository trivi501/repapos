<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\OrdenReparacion;
use Illuminate\Http\Request;

/**
 * Consulta pública del estado de una reparación.
 * - Por folio: el folio y el estado se muestran siempre; el resto solo si el cliente confirma
 *   los últimos 4 dígitos de su teléfono.
 * - Por enlace del ticket o de WhatsApp (token de 32 caracteres, imposible de adivinar): ya está verificado.
 * En ambos casos solo se muestra lo permitido en la sección 08 de la orden.
 */
class ConsultaController extends Controller
{
    public const SECCIONES = ['fotos_antes', 'fotos_proceso', 'fotos_despues', 'motivo', 'fechas', 'diagnostico', 'costos'];

    public function show(Request $request, string $folio)
    {
        $orden = $this->buscar('numero_orden', $folio);

        if (! $orden) {
            return response()->json(['message' => 'No encontramos una reparación con ese folio.'], 404);
        }

        $digitos = preg_replace('/\D/', '', (string) $request->query('telefono'));
        $telefono = preg_replace('/\D/', '', (string) $orden->cliente?->telefono);
        $verificado = strlen($digitos) === 4 && strlen($telefono) >= 4 && hash_equals(substr($telefono, -4), $digitos);

        return response()->json($this->datos($orden, $verificado));
    }

    /** Página del enlace que recibe el cliente en su comprobante o por WhatsApp. */
    public function porToken(string $token)
    {
        $orden = $this->buscar('token', $token);
        abort_unless($orden, 404);

        return response()->json($this->datos($orden, true) + [
            'enlace_pdf' => route('orden.pdf', $orden->token),
            'terminos' => Ajuste::valorDe($orden->taller_id, 'terminos_orden', Ajuste::TERMINOS_ORDEN),
        ]);
    }

    private function buscar(string $campo, string $valor): ?OrdenReparacion
    {
        return OrdenReparacion::withoutGlobalScopes()
            ->with([
                'equipo' => fn ($q) => $q->withoutGlobalScopes(),
                'cliente' => fn ($q) => $q->withoutGlobalScopes(),
                'fotos' => fn ($q) => $q->withoutGlobalScopes(),
                'sucursal' => fn ($q) => $q->withoutGlobalScopes(),
                'taller',
            ])
            ->where($campo, $valor)
            ->first();
    }

    private function datos(OrdenReparacion $orden, bool $verificado): array
    {
        $respuesta = [
            'folio' => $orden->numero_orden,
            'numero' => $orden->folio,
            'estado' => $orden->estado,
            'estado_texto' => $orden->estado_texto,
            // la liberación tiene sus propios pasos: recibido, en proceso, listo o rechazado
            'es_liberacion' => $orden->es_liberacion,
            'taller' => $orden->taller?->nombre,
            'logo_url' => $orden->taller?->logoUrl(),
            'sucursal' => $orden->sucursal?->nombre,
            'verificado' => $verificado,
        ];

        if (! $verificado) {
            return $respuesta;
        }

        $respuesta['equipo'] = $orden->equipo?->nombre;
        if ($orden->estado === OrdenReparacion::RECHAZADO) {
            $respuesta['motivo_rechazo'] = $orden->motivo_rechazo; // el cliente debe saber por qué
        }

        if (! $orden->visible_cliente) {
            return $respuesta;
        }

        $visibles = $orden->visibilidad ?? self::SECCIONES;

        foreach (['antes', 'proceso', 'despues'] as $categoria) {
            if (in_array("fotos_{$categoria}", $visibles, true)) {
                $respuesta["fotos_{$categoria}"] = $orden->fotos->where('categoria', $categoria)->pluck('url')->values();
            }
        }
        if (in_array('motivo', $visibles, true)) {
            $respuesta['motivo'] = $orden->falla_reportada;
        }
        if (in_array('fechas', $visibles, true)) {
            $respuesta['fechas'] = [
                'recepcion' => $orden->fecha_recepcion ?? $orden->created_at,
                'estimado_listo' => $orden->estimado_listo_at,
                'entrega' => $orden->fecha_entrega_real,
            ];
        }
        if (in_array('diagnostico', $visibles, true)) {
            $respuesta['diagnostico'] = $orden->diagnostico;
            $respuesta['solucion'] = $orden->solucion;
            $respuesta['notas_cliente'] = $orden->notas_cliente;
        }
        if (in_array('costos', $visibles, true)) {
            $respuesta['costos'] = [
                'total' => $orden->total,
                'anticipo' => (float) $orden->pagado,
                'saldo' => max(0, $orden->saldo),
            ];
        }

        return $respuesta;
    }
}

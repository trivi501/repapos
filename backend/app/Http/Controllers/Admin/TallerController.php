<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Taller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Panel del dueño del sistema: ver todos los talleres y activar, extender o suspender su acceso. */
class TallerController extends Controller
{
    public function index(Request $r)
    {
        $talleres = Taller::query()
            ->withCount('usuarios')
            ->addSelect(['telefonos_count' => DB::table('telefonos')->selectRaw('COUNT(*)')->whereColumn('taller_id', 'talleres.id')])
            ->addSelect(['ultimo_uso' => DB::table('telefonos')->selectRaw('MAX(created_at)')->whereColumn('taller_id', 'talleres.id')])
            ->with(['usuarios' => fn ($q) => $q->role(User::ROL_ADMIN)->orderBy('id')])
            ->when($r->filled('q'), fn ($q) => $q->where('nombre', 'like', '%'.$r->input('q').'%'))
            ->orderByDesc('id')
            ->get();

        $vigentes = $talleres->filter->vigente();

        return response()->json([
            'talleres' => $talleres->map(fn (Taller $t) => [
                'id' => $t->id, 'nombre' => $t->nombre, 'whatsapp' => $t->whatsapp, 'notas' => $t->notas, 'estado' => $t->estado,
                'estado_texto' => $t->estadoTexto(), 'vigente' => $t->vigente(),
                'admin' => $t->usuarios->first()?->only(['name', 'email']),
                'usuarios_count' => $t->usuarios_count, 'telefonos_count' => $t->telefonos_count,
                'ultimo_uso' => $t->ultimo_uso, 'created_at' => $t->created_at,
            ])->values(),
            'resumen' => [
                'total' => $talleres->count(),
                'pagando' => $talleres->where('estado', Taller::ACTIVO)->filter->vigente()->count(),
                'en_prueba' => $talleres->where('estado', Taller::PRUEBA)->filter->vigente()->count(),
                'vencidos' => $talleres->count() - $vigentes->count(),
            ],
        ]);
    }

    public function update(Request $r, Taller $taller)
    {
        $d = $r->validate([
            'accion' => ['required', 'in:activar,extender_prueba,suspender,cortesia,notas'],
            'meses' => ['nullable', 'integer', 'min:1', 'max:36'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        switch ($d['accion']) {
            case 'activar':
                // se suma a lo que ya tenga pagado (si sigue vigente) o desde hoy
                $base = $taller->estado === Taller::ACTIVO && $taller->pagado_hasta?->gte(today()) ? $taller->pagado_hasta : today();
                $taller->update(['estado' => Taller::ACTIVO, 'pagado_hasta' => $base->copy()->addMonths((int) ($d['meses'] ?? 1))]);
                $msg = "{$taller->nombre}: plan activo hasta ".$taller->pagado_hasta->format('d/m/Y').'.';
                break;
            case 'extender_prueba':
                $base = $taller->prueba_hasta?->gte(today()) ? $taller->prueba_hasta : today();
                $taller->update(['estado' => Taller::PRUEBA, 'prueba_hasta' => $base->copy()->addDays((int) ($d['dias'] ?? 7))]);
                $msg = "{$taller->nombre}: prueba hasta ".$taller->prueba_hasta->format('d/m/Y').'.';
                break;
            case 'suspender':
                $taller->update(['estado' => Taller::SUSPENDIDO]);
                $msg = "{$taller->nombre} suspendido: ya no puede entrar (sus datos se conservan).";
                break;
            case 'cortesia':
                $taller->update(['estado' => Taller::CORTESIA]);
                $msg = "{$taller->nombre} con acceso de cortesía (sin vencimiento).";
                break;
            default:
                $taller->update(['notas' => trim($d['notas'] ?? '')]);
                $msg = 'Notas guardadas.';
        }

        return response()->json(['message' => $msg]);
    }
}

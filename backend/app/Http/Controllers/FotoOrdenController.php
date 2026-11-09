<?php

namespace App\Http\Controllers;

use App\Models\FotoOrden;
use App\Models\OrdenReparacion;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class FotoOrdenController extends Controller
{
    private const REGLA_ARCHIVO = ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:10240'];

    public function index(Request $request)
    {
        $datos = $request->validate([
            'lote' => ['required_without:orden_id', 'nullable', 'uuid'],
            'orden_id' => ['required_without:lote', 'nullable', 'integer'],
        ]);

        // el filtro por taller lo pone el modelo: solo se ven fotos del taller del usuario
        $fotos = FotoOrden::query()
            ->when($datos['lote'] ?? null, fn ($q, $lote) => $q->where('lote', $lote)->whereNull('orden_reparacion_id'))
            ->when($datos['orden_id'] ?? null, fn ($q, $id) => $q->where('orden_reparacion_id', $id))
            ->orderBy('id')
            ->get();

        return response()->json($fotos);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'archivo' => self::REGLA_ARCHIVO,
            'categoria' => ['required', Rule::in(FotoOrden::CATEGORIAS)],
            'lote' => ['required', 'uuid'],
        ]);

        $foto = $this->guardar($request->file('archivo'), $datos['categoria'], $datos['lote'], $request->user()->taller_id, $request->user()->id);

        return response()->json($foto, 201);
    }

    public function destroy(Request $request, FotoOrden $foto)
    {
        if ($foto->orden_reparacion_id && ! $request->user()->can('ordenes.editar')) {
            abort(403, 'No tienes permiso para eliminar fotos de una orden guardada.');
        }

        $foto->delete();

        return response()->json(null, 204);
    }

    /**
     * Genera un enlace firmado (30 min) para subir fotos desde el celular escaneando un QR.
     * La firma es relativa para que el enlace funcione con la IP de red local (MOBILE_UPLOAD_URL).
     */
    public function enlaceMovil(Request $request)
    {
        $datos = $request->validate([
            'lote' => ['required', 'uuid'],
            'categoria' => ['required', Rule::in(FotoOrden::CATEGORIAS)],
        ]);

        // el taller va dentro de la firma: en el celular no hay sesión de la que tomarlo
        $ruta = URL::temporarySignedRoute('fotos.movil', now()->addMinutes(30), $datos + ['taller' => $request->user()->taller_id], absolute: false);

        return response()->json([
            'url' => rtrim(config('app.mobile_url'), '/').$ruta,
            'expira' => now()->addMinutes(30)->toIso8601String(),
        ]);
    }

    public function vistaMovil(Request $request, string $lote, string $categoria)
    {
        abort_unless(in_array($categoria, FotoOrden::CATEGORIAS, true), 404);

        $urlSubida = URL::temporarySignedRoute(
            'fotos.movil.subir',
            now()->addMinutes(30),
            ['lote' => $lote, 'categoria' => $categoria, 'taller' => $request->integer('taller')],
            absolute: false,
        );

        return view('fotos-movil', [
            'categoria' => ['antes' => 'Fotos antes', 'proceso' => 'Evidencias del proceso', 'despues' => 'Fotos después'][$categoria],
            'urlSubida' => $urlSubida,
        ]);
    }

    public function subirMovil(Request $request, string $lote, string $categoria)
    {
        abort_unless(in_array($categoria, FotoOrden::CATEGORIAS, true), 404);
        $request->validate(['archivo' => self::REGLA_ARCHIVO]);

        $foto = $this->guardar($request->file('archivo'), $categoria, $lote, $request->integer('taller'), null);

        return response()->json($foto, 201);
    }

    private function guardar(UploadedFile $archivo, string $categoria, string $lote, int $tallerId, ?int $userId): FotoOrden
    {
        return FotoOrden::create([
            'taller_id' => $tallerId,
            'lote' => $lote,
            'categoria' => $categoria,
            'ruta' => $archivo->store('fotos/'.now()->format('Y/m'), 'public'),
            'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'tamano' => $archivo->getSize(),
            'user_id' => $userId,
        ]);
    }
}

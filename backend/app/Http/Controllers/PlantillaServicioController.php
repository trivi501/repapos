<?php

namespace App\Http\Controllers;

use App\Models\PlantillaServicio;
use Illuminate\Http\Request;

/** Plantillas de servicios: se eligen al dar de alta o editar un teléfono de locatario. */
class PlantillaServicioController extends Controller
{
    public function index()
    {
        return response()->json(PlantillaServicio::orderBy('nombre')->get());
    }

    /** Guarda una plantilla; si ya hay una con ese nombre, le actualiza el precio. */
    public function store(Request $r)
    {
        $d = $r->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'precio' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], [
            'nombre.required' => 'Escribe el servicio (ej. FRP por ISP).',
            'precio.required' => 'Escribe el precio del servicio.',
        ]);
        $nombre = preg_replace('/\s+/', ' ', trim($d['nombre']));
        $existente = PlantillaServicio::where('nombre', $nombre)->first();
        $plantilla = PlantillaServicio::updateOrCreate(['nombre' => $nombre], ['precio' => round((float) $d['precio'], 2)]);

        return response()->json([
            'message' => ($existente ? 'Plantilla actualizada: ' : 'Plantilla guardada: ')."{$plantilla->nombre} ".dinero($plantilla->precio),
            'plantilla' => $plantilla,
        ], $existente ? 200 : 201);
    }

    public function destroy(PlantillaServicio $plantilla)
    {
        $plantilla->delete();

        return response()->json(['message' => "Se quitó la plantilla {$plantilla->nombre}."]);
    }
}

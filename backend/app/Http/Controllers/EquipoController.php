<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipoController extends Controller
{
    public function index(Request $request)
    {
        $equipos = Equipo::query()
            ->with('cliente')
            ->when($request->query('cliente_id'), fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->when($request->query('buscar'), function ($query, $buscar) {
                $query->where(function ($q) use ($buscar) {
                    $q->where('marca', 'like', "%{$buscar}%")
                        ->orWhere('modelo', 'like', "%{$buscar}%")
                        ->orWhere('imei', 'like', "%{$buscar}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($request->integer('por_pagina', 15));

        return response()->json($equipos);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('taller_id', $request->user()->taller_id)],
            'tipo' => ['required', 'string', 'max:50'],
            'marca' => ['required', 'string', 'max:100'],
            'modelo' => ['required', 'string', 'max:100'],
            'imei' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'patron_desbloqueo' => ['nullable', 'string', 'max:100'],
            'accesorios' => ['nullable', 'string', 'max:255'],
            'condicion_inicial' => ['nullable', 'string'],
        ]);

        $equipo = Equipo::create($datos);

        return response()->json($equipo->load('cliente'), 201);
    }

    public function show(Equipo $equipo)
    {
        return response()->json($equipo->load(['cliente', 'ordenesReparacion']));
    }

    public function update(Request $request, Equipo $equipo)
    {
        $datos = $request->validate([
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('taller_id', $request->user()->taller_id)],
            'tipo' => ['required', 'string', 'max:50'],
            'marca' => ['required', 'string', 'max:100'],
            'modelo' => ['required', 'string', 'max:100'],
            'imei' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'patron_desbloqueo' => ['nullable', 'string', 'max:100'],
            'accesorios' => ['nullable', 'string', 'max:255'],
            'condicion_inicial' => ['nullable', 'string'],
        ]);

        $equipo->update($datos);

        return response()->json($equipo->load('cliente'));
    }

    public function destroy(Equipo $equipo)
    {
        $equipo->delete();

        return response()->json(null, 204);
    }
}

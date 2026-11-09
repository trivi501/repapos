<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $clientes = Cliente::query()
            ->when($request->query('buscar'), function ($query, $buscar) {
                $query->where(function ($q) use ($buscar) {
                    $q->where('nombre', 'like', "%{$buscar}%")
                        ->orWhere('apellido', 'like', "%{$buscar}%")
                        ->orWhere('telefono', 'like', "%{$buscar}%")
                        ->orWhere('email', 'like', "%{$buscar}%");
                });
            })
            ->orderBy('nombre')
            ->paginate($request->integer('por_pagina', 15));

        return response()->json($clientes);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
            'telefono2' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string'],
        ]);

        $cliente = Cliente::create($this->normalizar($datos));

        return response()->json($cliente, 201);
    }

    public function show(Cliente $cliente)
    {
        return response()->json($cliente->load(['equipos', 'ordenesReparacion']));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
            'telefono2' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string'],
        ]);

        $cliente->update($this->normalizar($datos));

        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return response()->json(null, 204);
    }

    /** La columna telefono2 no admite nulos: vacío es cadena vacía. */
    private function normalizar(array $datos): array
    {
        return ['telefono2' => ''] + array_filter($datos, fn ($v, $k) => $k !== 'telefono2' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}

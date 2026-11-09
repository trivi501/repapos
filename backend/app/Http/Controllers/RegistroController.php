<?php

namespace App\Http\Controllers;

use App\Models\Taller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Un taller nuevo se da de alta solo y empieza su prueba gratis. */
class RegistroController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate([
            'taller' => ['required', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'taller.required' => 'Escribe el nombre de tu taller.',
            'whatsapp.regex' => 'El WhatsApp debe tener al menos 10 dígitos.',
        ]);

        $user = DB::transaction(function () use ($d) {
            $taller = Taller::create([
                'nombre' => trim($d['taller']),
                'whatsapp' => preg_replace('/\D/', '', (string) ($d['whatsapp'] ?? '')) ?: null,
                'estado' => Taller::PRUEBA,
                'prueba_hasta' => today()->addDays(config('venta.dias_prueba')),
            ]);
            $user = User::create([
                'taller_id' => $taller->id, 'name' => trim($d['name']), 'email' => $d['email'], 'password' => $d['password'],
            ]);
            $user->assignRole(User::ROL_ADMIN);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken('sistema-taller')->plainTextToken,
            'user' => AuthController::formatUser($user),
            'message' => '¡Bienvenido! Tu prueba gratis de '.config('venta.dias_prueba').' días ya empezó.',
        ], 201);
    }

    /** Datos públicos del producto para la página de venta. */
    public function producto()
    {
        return response()->json(collect(config('venta'))->only(['producto', 'dias_prueba', 'precio_mensual', 'precio_anual', 'moneda', 'whatsapp']));
    }
}

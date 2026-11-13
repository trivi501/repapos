<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $token = $user->createToken('sistema-taller')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => self::formatUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request)
    {
        return response()->json(self::formatUser($request->user()));
    }

    public static function formatUser(User $user): array
    {
        $taller = $user->taller;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'es_superadmin' => $user->esSuperadmin(),
            'sucursal_id' => $user->sucursal_id,
            'sucursales' => $user->taller_id
                ? Sucursal::withoutGlobalScopes()->where('taller_id', $user->taller_id)->orderBy('id')->get(['id', 'nombre'])
                : [],
            'taller' => $taller ? [
                'id' => $taller->id,
                'nombre' => $taller->nombre,
                'logo_url' => $taller->logoUrl(),
                'vigente' => $taller->vigente(),
                'estado_texto' => $taller->estadoTexto(),
                // con la API de WhatsApp activa, los botones de WhatsApp envían directo
                'whatsapp_api' => \App\Support\WhatsAppCloud::activaPara($taller->id),
            ] : null,
        ];
    }
}

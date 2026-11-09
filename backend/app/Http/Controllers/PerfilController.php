<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cada quien cambia su nombre, correo y contraseña. */
class PerfilController extends Controller
{
    public function update(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($u->id)],
            'password_actual' => ['required_with:password', 'nullable', 'current_password:sanctum'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ], ['password_actual.current_password' => 'La contraseña actual no es correcta.']);

        $u->fill(['name' => $d['name'], 'email' => $d['email']]);
        if ($u->isDirty('email')) {
            $u->email_verified_at = null;
        }
        if (! empty($d['password'])) {
            $u->password = $d['password'];
        }
        $u->save();

        return response()->json(['message' => 'Perfil actualizado.', 'user' => AuthController::formatUser($u)]);
    }
}

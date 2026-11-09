<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** El administrador de cada taller da de alta y de baja a su gente (solo ve los usuarios de su taller). */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $usuarios = User::query()
            ->where('taller_id', $request->user()->taller_id)
            ->with(['roles:id,name', 'sucursal:id,nombre'])
            ->when($request->query('buscar'), function ($query, $buscar) {
                $query->where(function ($q) use ($buscar) {
                    $q->where('name', 'like', "%{$buscar}%")
                        ->orWhere('email', 'like', "%{$buscar}%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('por_pagina', 15));

        return response()->json($usuarios);
    }

    /** Reglas comunes: la sucursal debe ser de su taller y solo el dueño del sistema puede otorgar el rol Superadmin. */
    private function reglas(Request $request): array
    {
        $roles = Rule::exists('roles', 'name');

        return [
            'name' => ['required', 'string', 'max:255'],
            'roles' => ['array'],
            'roles.*' => ['string', $roles, $request->user()->esSuperadmin() ? 'string' : Rule::notIn([User::ROL_SUPERADMIN])],
            'sucursal_id' => ['nullable', Rule::exists('sucursales', 'id')->where('taller_id', $request->user()->taller_id)],
        ];
    }

    public function store(Request $request)
    {
        $datos = $request->validate($this->reglas($request) + [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        $usuario = User::create([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'password' => $datos['password'],
            'taller_id' => $request->user()->taller_id,
            'sucursal_id' => $datos['sucursal_id'] ?? null,
        ]);

        $usuario->syncRoles($datos['roles'] ?? []);

        return response()->json($usuario->load('roles:id,name'), 201);
    }

    public function show(Request $request, User $user)
    {
        $this->mismoTaller($request, $user);

        return response()->json($user->load('roles:id,name'));
    }

    public function update(Request $request, User $user)
    {
        $this->puedeModificar($request, $user);

        $datos = $request->validate($this->reglas($request) + [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)],
        ]);

        $roles = $datos['roles'] ?? [];
        if ($user->is($request->user()) && $user->hasRole(User::ROL_ADMIN) && ! in_array(User::ROL_ADMIN, $roles, true)) {
            return response()->json(['message' => 'No puedes quitarte a ti mismo el permiso de administrador.'], 422);
        }

        $user->fill(['name' => $datos['name'], 'email' => $datos['email'], 'sucursal_id' => $datos['sucursal_id'] ?? null]);
        if (! empty($datos['password'])) {
            $user->password = $datos['password'];
        }
        $user->save();

        // el rol Superadmin de un usuario no se puede perder desde este formulario
        if ($user->esSuperadmin() && ! in_array(User::ROL_SUPERADMIN, $roles, true)) {
            $roles[] = User::ROL_SUPERADMIN;
        }
        $user->syncRoles($roles);

        return response()->json($user->load('roles:id,name'));
    }

    public function destroy(Request $request, User $user)
    {
        $this->puedeModificar($request, $user);

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 422);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    private function mismoTaller(Request $request, User $usuario): void
    {
        abort_unless($usuario->taller_id === $request->user()->taller_id, 404);
    }

    /** Un administrador no puede cambiarle la contraseña ni borrar al dueño del sistema. */
    private function puedeModificar(Request $request, User $usuario): void
    {
        $this->mismoTaller($request, $usuario);
        abort_if($usuario->esSuperadmin() && ! $request->user()->esSuperadmin(), 403, 'No puedes modificar al dueño del sistema.');
    }
}

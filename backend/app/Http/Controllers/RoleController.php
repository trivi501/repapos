<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Los roles son globales (compartidos por todos los talleres), por eso crearlos o cambiarlos
 * solo lo puede hacer el dueño del sistema. Los demás solo los ven para asignarlos a su gente.
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::with('permissions:id,name')
            ->when(! $request->user()->esSuperadmin(), fn ($q) => $q->where('name', '!=', User::ROL_SUPERADMIN))
            ->orderBy('name')
            ->get();

        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $datos['name'], 'guard_name' => 'web']);
        $role->syncPermissions($datos['permissions'] ?? []);

        return response()->json($role->load('permissions:id,name'), 201);
    }

    public function show(Role $role)
    {
        return response()->json($role->load('permissions:id,name'));
    }

    public function update(Request $request, Role $role)
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // el sistema busca estos roles por nombre
        if (in_array($role->name, [User::ROL_SUPERADMIN, User::ROL_ADMIN], true) && $datos['name'] !== $role->name) {
            return response()->json(['message' => 'A este rol no se le puede cambiar el nombre.'], 422);
        }

        $role->update(['name' => $datos['name']]);
        $role->syncPermissions($datos['permissions'] ?? []);

        return response()->json($role->load('permissions:id,name'));
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, [User::ROL_SUPERADMIN, User::ROL_ADMIN], true)) {
            return response()->json(['message' => 'Este rol no se puede eliminar.'], 422);
        }

        $role->delete();

        return response()->json(null, 204);
    }
}

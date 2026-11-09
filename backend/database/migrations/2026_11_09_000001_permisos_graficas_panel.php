<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cada gráfica del panel tiene su permiso. Los administradores las ven todas por su rol. A los demás usuarios
 * se les asignan directamente, para poder elegirlas persona por persona, y de inicio solo las que no muestran dinero.
 */
return new class extends Migration
{
    private const GRAFICAS = ['panel.ingresos', 'panel.ventas', 'panel.reparaciones', 'panel.telefonos', 'panel.cobranza'];

    private const BASICAS = ['panel.reparaciones', 'panel.telefonos'];

    private const ADMINISTRADORES = ['Superadmin', 'Administrador'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRAFICAS as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        Role::whereIn('name', self::ADMINISTRADORES)->get()->each->givePermissionTo(self::GRAFICAS);

        User::withoutGlobalScopes()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Empleado', 'Recepcionista', 'Tecnico']))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::ADMINISTRADORES))
            ->get()->each->givePermissionTo(self::BASICAS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::GRAFICAS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

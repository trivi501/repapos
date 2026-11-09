<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** Permisos sueltos, además del CRUD de cada módulo. */
    public const EXTRAS = [
        'ordenes.cambiar_estado', 'ordenes.cobrar',
        'telefonos.cobrar',
        'resumen.ver',
        'saldos.ver', 'saldos.gestionar',
        'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.devolver', 'ventas.cancelar',
        'inventario.movimiento',
        'cortes.ver', 'cortes.guardar',
        'configuracion.ver', 'configuracion.editar',
        'taller.editar',
        'respaldo.descargar',
        'talleres.administrar',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modulos = ['usuarios', 'roles', 'clientes', 'equipos', 'ordenes', 'telefonos', 'inventario', 'salidas', 'sucursales'];
        $acciones = ['ver', 'crear', 'editar', 'eliminar'];

        foreach ($modulos as $modulo) {
            foreach ($acciones as $accion) {
                Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']);
            }
        }
        foreach (self::EXTRAS as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $todos = Permission::pluck('name');

        // dueño del sistema: los roles son globales, así que solo él puede modificarlos
        Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web'])->syncPermissions($todos);

        // administrador de su taller
        Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web'])->syncPermissions(
            $todos->diff(['talleres.administrar', 'roles.crear', 'roles.editar', 'roles.eliminar'])
        );

        // empleado de RepaPOS: opera todo, menos lo que allá era solo del administrador
        Role::firstOrCreate(['name' => 'Empleado', 'guard_name' => 'web'])->syncPermissions(
            $todos->reject(fn ($p) => str_starts_with($p, 'usuarios.') || str_starts_with($p, 'roles.') || str_starts_with($p, 'sucursales.'))
                ->diff(['ventas.cancelar', 'taller.editar', 'respaldo.descargar', 'talleres.administrar'])
        );

        Role::firstOrCreate(['name' => 'Recepcionista', 'guard_name' => 'web'])->syncPermissions([
            'clientes.ver', 'clientes.crear', 'clientes.editar',
            'equipos.ver', 'equipos.crear', 'equipos.editar',
            'ordenes.ver', 'ordenes.crear', 'ordenes.editar', 'ordenes.cambiar_estado', 'ordenes.cobrar',
            'telefonos.ver', 'telefonos.crear', 'telefonos.editar', 'telefonos.cobrar',
            'resumen.ver', 'saldos.ver', 'saldos.gestionar',
            'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.devolver',
            'inventario.ver', 'inventario.movimiento',
            'cortes.ver', 'cortes.guardar',
            'salidas.ver', 'salidas.crear', 'salidas.editar',
            'configuracion.ver',
        ]);

        Role::firstOrCreate(['name' => 'Tecnico', 'guard_name' => 'web'])->syncPermissions([
            'clientes.ver',
            'equipos.ver',
            'ordenes.ver', 'ordenes.editar', 'ordenes.cambiar_estado',
            'telefonos.ver', 'telefonos.editar',
            'inventario.ver',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

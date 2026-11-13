<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Costos internos de cada reparación (lo que costó la refacción y lo que se paga de mano de obra) para que el
 * dueño vea la ganancia del local. Solo los ve quien tiene "ordenes.ver_costos": de inicio, los administradores.
 */
return new class extends Migration
{
    private const PERMISO = 'ordenes.ver_costos';

    public function up(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->decimal('costo_refaccion', 10, 2)->nullable()->after('costo_final');
            $table->decimal('costo_mano_obra', 10, 2)->nullable()->after('costo_refaccion');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => self::PERMISO, 'guard_name' => 'web']);
        Role::whereIn('name', ['Superadmin', 'Administrador'])->get()->each->givePermissionTo(self::PERMISO);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn(['costo_refaccion', 'costo_mano_obra']);
        });
        Permission::where('name', self::PERMISO)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

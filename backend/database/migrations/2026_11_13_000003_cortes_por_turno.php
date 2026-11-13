<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cortes por turno: un día puede tener varios cortes; cada uno cubre de "desde" a "hasta".
 * Al cerrar se dice cuánto se retira y lo demás queda de fondo para el siguiente turno o el día siguiente.
 * Además, cada orden guarda cuándo se terminó, para sumar la ganancia del día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cortes', function (Blueprint $table) {
            $table->unsignedTinyInteger('turno')->default(1)->after('fecha');
            $table->dateTime('desde')->nullable()->after('turno');     // null = desde las 00:00 del día
            $table->dateTime('hasta')->nullable()->after('desde');     // null = hasta el fin del día
            $table->decimal('retiro', 10, 2)->default(0)->after('contado');
            $table->decimal('fondo_siguiente', 10, 2)->nullable()->after('retiro');
        });

        // primero el índice nuevo (también sirve a la llave de sucursal_id), luego se quita el de un corte por día
        Schema::table('cortes', function (Blueprint $table) {
            $table->unique(['sucursal_id', 'fecha', 'turno']);
        });
        Schema::table('cortes', function (Blueprint $table) {
            $table->dropUnique(['sucursal_id', 'fecha']);
        });

        // los cortes que ya existían dejaban el mismo fondo para el día siguiente
        DB::table('cortes')->update(['fondo_siguiente' => DB::raw('fondo')]);

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dateTime('terminado_at')->nullable()->after('fecha_entrega_real');
        });
        DB::table('ordenes_reparacion')
            ->whereIn('estado', ['reparado', 'no_quedo', 'rechazado', 'entregado', 'cancelado'])
            ->update(['terminado_at' => DB::raw('COALESCE(fecha_entrega_real, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn('terminado_at');
        });

        // si hubo varios turnos en un día, se queda solo el primero
        DB::table('cortes')->where('turno', '>', 1)->delete();
        Schema::table('cortes', function (Blueprint $table) {
            $table->unique(['sucursal_id', 'fecha']);
        });
        Schema::table('cortes', function (Blueprint $table) {
            $table->dropUnique(['sucursal_id', 'fecha', 'turno']);
            $table->dropColumn(['turno', 'desde', 'hasta', 'retiro', 'fondo_siguiente']);
        });
    }
};

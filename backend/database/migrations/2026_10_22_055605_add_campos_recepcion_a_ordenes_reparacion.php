<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->timestamps();
        });

        DB::table('sucursales')->insert([
            'nombre' => 'Sucursal Principal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('tecnico_id')->constrained('sucursales')->nullOnDelete();
            $table->string('tipo_servicio')->default('reparacion')->after('prioridad');
            $table->string('bloqueo_tipo')->default('ninguno')->after('tipo_servicio');
            $table->string('bloqueo_valor')->nullable()->after('bloqueo_tipo');
            $table->json('estado_fisico')->nullable()->after('falla_reportada');
            $table->decimal('anticipo', 10, 2)->default(0)->after('costo_final');
            $table->string('metodo_pago')->default('efectivo')->after('anticipo');
            $table->dateTime('estimado_listo_at')->nullable()->after('fecha_entrega_estimada');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
            $table->dropColumn([
                'tipo_servicio', 'bloqueo_tipo', 'bloqueo_valor', 'estado_fisico',
                'anticipo', 'metodo_pago', 'estimado_listo_at',
            ]);
        });

        Schema::dropIfExists('sucursales');
    }
};

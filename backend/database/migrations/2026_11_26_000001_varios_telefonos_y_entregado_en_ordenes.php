<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - "grupo": los teléfonos que un cliente deja en una misma orden (un ticket, un cobro). Cada teléfono
 *   sigue siendo su propia fila, con su falla, precio y estatus.
 * - "Entregado" deja de ser un estatus: se marca aparte con fecha_entrega_real, así una orden puede
 *   estar Listo / No quedó / Rechazado y además entregada. Las que estaban "entregado" pasan a Listo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->uuid('grupo')->nullable()->after('numero_orden')->index();
        });

        DB::table('ordenes_reparacion')->where('estado', 'entregado')->update([
            'estado' => 'reparado',
            'fecha_entrega_real' => DB::raw('COALESCE(fecha_entrega_real, updated_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        DB::table('ordenes_reparacion')->where('estado', 'reparado')->whereNotNull('fecha_entrega_real')
            ->update(['estado' => 'entregado']);

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn('grupo');
        });
    }
};

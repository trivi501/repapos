<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Refacciones de cada reparación: del inventario (descuentan la pieza) o compradas fuera. Su suma es el costo de refacción.
 * - Mano externa: lo que se le paga a otro taller o técnico cuando el equipo se manda fuera. Resta de la ganancia.
 * - Cuántas veces el cliente consultó el estatus de su equipo (enlace del comprobante o búsqueda por folio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->decimal('costo_mano_externa', 10, 2)->nullable()->after('costo_mano_obra');
            $table->string('mano_externa_detalle', 255)->nullable()->after('costo_mano_externa');
            $table->unsignedInteger('consultas_cliente')->default(0);
            $table->timestamp('ultima_consulta_at')->nullable();
        });

        Schema::create('orden_refacciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('orden_reparacion_id')->constrained('ordenes_reparacion')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('movimiento_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->string('descripcion', 255);
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('costo_unitario', 10, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_refacciones');
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn(['costo_mano_externa', 'mano_externa_detalle', 'consultas_cliente', 'ultima_consulta_at']);
        });
    }
};

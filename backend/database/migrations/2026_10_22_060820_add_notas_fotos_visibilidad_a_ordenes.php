<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->text('notas_cliente')->nullable()->after('notas');
            $table->boolean('visible_cliente')->default(true)->after('notas_cliente');
            // Secciones visibles en la consulta pública: fotos_antes, fotos_proceso, fotos_despues,
            // motivo, fechas, diagnostico, costos.
            $table->json('visibilidad')->nullable()->after('visible_cliente');
        });

        Schema::create('fotos_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_reparacion_id')->nullable()->constrained('ordenes_reparacion')->cascadeOnDelete();
            // Lote de borrador: agrupa fotos subidas antes de guardar la orden.
            $table->uuid('lote')->nullable()->index();
            $table->enum('categoria', ['antes', 'proceso', 'despues']);
            $table->string('ruta');
            $table->string('nombre_original')->nullable();
            $table->unsignedInteger('tamano')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_orden');

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn(['notas_cliente', 'visible_cliente', 'visibilidad']);
        });
    }
};

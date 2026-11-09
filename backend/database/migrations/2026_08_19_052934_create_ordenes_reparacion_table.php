<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ordenes_reparacion', function (Blueprint $table) {
            $table->id();
            $table->string('numero_orden')->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('equipo_id')->constrained('equipos')->cascadeOnDelete();
            $table->foreignId('tecnico_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('estado', [
                'recibido',
                'diagnostico',
                'en_reparacion',
                'esperando_repuesto',
                'reparado',
                'entregado',
                'cancelado',
            ])->default('recibido');
            $table->enum('prioridad', ['baja', 'media', 'alta'])->default('media');
            $table->text('falla_reportada');
            $table->text('diagnostico')->nullable();
            $table->text('solucion')->nullable();
            $table->decimal('costo_estimado', 10, 2)->nullable();
            $table->decimal('costo_final', 10, 2)->nullable();
            $table->dateTime('fecha_recepcion')->useCurrent();
            $table->date('fecha_entrega_estimada')->nullable();
            $table->dateTime('fecha_entrega_real')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ordenes_reparacion');
    }
};

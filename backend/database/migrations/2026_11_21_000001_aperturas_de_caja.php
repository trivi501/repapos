<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apertura de caja: quién la abrió, a qué hora y con cuánto fondo contado. Al cerrarla se guarda el corte del turno
 * (con ese fondo) y la apertura queda ligada a él. Una sucursal solo tiene una caja abierta a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aperturas_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->date('fecha');
            $table->dateTime('abierta_at');
            $table->decimal('fondo', 10, 2)->default(0);
            $table->string('notas', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cerrada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corte_id')->nullable()->constrained('cortes')->nullOnDelete();
            $table->timestamps();
            $table->index(['sucursal_id', 'cerrada_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aperturas_caja');
    }
};

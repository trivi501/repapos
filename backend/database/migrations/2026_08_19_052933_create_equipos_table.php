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
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('tipo')->default('celular');
            $table->string('marca');
            $table->string('modelo');
            $table->string('imei')->nullable();
            $table->string('color')->nullable();
            $table->string('patron_desbloqueo')->nullable();
            $table->string('accesorios')->nullable();
            $table->text('condicion_inicial')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};

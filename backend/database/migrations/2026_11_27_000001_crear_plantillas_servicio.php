<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Servicios que el taller hace seguido (FRP por ISP $250…) para llenar trabajo y precio de un clic. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->decimal('precio', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['taller_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_servicio');
    }
};

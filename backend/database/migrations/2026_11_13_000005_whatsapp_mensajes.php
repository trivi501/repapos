<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los mensajes que se mandan por la API de WhatsApp Cloud (Meta).
 * Las credenciales de cada taller van en "ajustes" (el token cifrado con APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('telefono', 20);
            $table->string('tipo', 10);            // plantilla o texto
            $table->text('texto');
            $table->string('estado', 10);          // enviado o fallido
            $table->string('error')->nullable();
            $table->string('meta_id')->nullable(); // id que regresa Meta (wamid...)
            $table->timestamps();
            $table->index(['taller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
    }
};

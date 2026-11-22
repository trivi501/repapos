<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Por qué no quedó un teléfono de locatario: se pide al marcarlo y va en el WhatsApp de aviso. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->string('motivo_no_quedo', 255)->nullable()->after('resultado');
        });
    }

    public function down(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->dropColumn('motivo_no_quedo');
        });
    }
};

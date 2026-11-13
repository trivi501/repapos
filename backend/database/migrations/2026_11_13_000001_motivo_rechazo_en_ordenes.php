<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            // Liberación (la orden trae bloqueos): por qué se rechazó, si se rechazó.
            $table->string('motivo_rechazo')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropColumn('motivo_rechazo');
        });
    }
};

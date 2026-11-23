<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El saldo a favor también se aplica a ventas a crédito: el movimiento guarda a qué venta se aplicó. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable()->after('telefono_id')->constrained('ventas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venta_id');
        });
    }
};

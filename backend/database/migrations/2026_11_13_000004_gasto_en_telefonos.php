<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gasto de cada teléfono de locatario (créditos, servidor o pieza) para ver la ganancia. Solo lo ve el dueño. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->decimal('costo', 10, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->dropColumn('costo');
        });
    }
};

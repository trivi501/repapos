<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serie, IMEI y PIN de cada teléfono de locatario. La serie y el IMEI se llenan solos al leer el teléfono por USB
 * y sirven para encontrarlo después (¿ya vino?, ¿entra en garantía?). El PIN se guarda cifrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->string('serie', 100)->nullable()->after('modelo');
            $table->string('imei', 30)->nullable()->after('serie');
            $table->text('pin')->nullable()->after('imei');
            $table->index('serie');
            $table->index('imei');
        });
    }

    public function down(): void
    {
        Schema::table('telefonos', function (Blueprint $table) {
            $table->dropIndex(['serie']);
            $table->dropIndex(['imei']);
            $table->dropColumn(['serie', 'imei', 'pin']);
        });
    }
};

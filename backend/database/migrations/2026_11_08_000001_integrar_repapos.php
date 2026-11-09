<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Integra el dominio de RepaPOS: talleres (multi-empresa), locatarios, teléfonos, caja, cortes,
 * saldos a favor, inventario y punto de venta. Lo que ya existía queda en un "Taller de pruebas".
 */
return new class extends Migration
{
    public function up(): void
    {
        // cada cliente del sistema es un taller; todos sus datos llevan taller_id
        Schema::create('talleres', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('whatsapp', 20)->nullable();
            $table->string('logo')->nullable();                 // ruta dentro del disco local (logos/...)
            $table->string('estado', 12)->default('prueba');    // prueba, activo, suspendido, cortesia
            $table->date('prueba_hasta')->nullable();
            $table->date('pagado_hasta')->nullable();
            $table->string('notas')->default('');               // notas del dueño del sistema
            $table->timestamps();
        });

        $hayDatos = DB::table('users')->exists() || DB::table('clientes')->exists();
        $tallerId = null;
        if ($hayDatos) {
            $tallerId = DB::table('talleres')->insertGetId([
                'nombre' => 'Taller de pruebas', 'estado' => 'cortesia', 'notas' => 'Datos de prueba previos a la migración de RepaPOS.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            // instalación nueva: la sucursal suelta de la migración anterior no pertenece a nadie
            DB::table('sucursales')->delete();
        }

        Schema::table('sucursales', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
            $table->string('direccion')->default('')->after('nombre');
            $table->string('telefono', 30)->default('')->after('direccion');
        });
        DB::table('sucursales')->whereNull('taller_id')->update(['taller_id' => $tallerId]);
        $sucursalId = $tallerId ? DB::table('sucursales')->where('taller_id', $tallerId)->orderBy('id')->value('id') : null;

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
            // null = puede cambiar entre todas las sucursales de su taller
            $table->foreignId('sucursal_id')->nullable()->after('taller_id')->constrained('sucursales')->nullOnDelete();
        });
        DB::table('users')->whereNull('taller_id')->update(['taller_id' => $tallerId]);

        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
            $table->string('telefono2', 20)->default('')->after('telefono');
            $table->index(['taller_id', 'telefono']);
        });
        DB::table('clientes')->whereNull('taller_id')->update(['taller_id' => $tallerId]);

        Schema::table('equipos', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
            $table->text('patron_desbloqueo')->nullable()->change();   // ahora se guarda cifrado
        });
        DB::table('equipos')->whereNull('taller_id')->update(['taller_id' => $tallerId]);
        foreach (DB::table('equipos')->whereNotNull('patron_desbloqueo')->get(['id', 'patron_desbloqueo']) as $e) {
            DB::table('equipos')->where('id', $e->id)->update(['patron_desbloqueo' => Crypt::encryptString($e->patron_desbloqueo)]);
        }

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
            $table->unsignedInteger('folio')->nullable()->after('numero_orden');      // consecutivo por taller
            $table->string('token', 40)->nullable()->after('folio');                  // enlace público para consultar el estatus
            $table->foreignId('user_id')->nullable()->after('tecnico_id')->constrained('users')->nullOnDelete();
            $table->decimal('pagado', 10, 2)->default(0)->after('anticipo');          // suma de sus cobros en caja
            $table->json('revision')->nullable()->after('estado_fisico');             // {"bateria":"si","golpes":"no",...}
            $table->text('observaciones')->nullable()->after('revision');
            $table->text('bloqueo_pin')->nullable()->after('bloqueo_valor');          // PIN adicional al patrón (cifrado)
            $table->text('bloqueo_valor')->nullable()->change();                      // ahora se guarda cifrado
            $table->string('estado', 20)->default('recibido')->change();              // se agrega "no_quedo"
            $table->string('prioridad', 10)->default('media')->change();
        });

        $folio = 0;
        foreach (DB::table('ordenes_reparacion')->orderBy('id')->get() as $o) {
            DB::table('ordenes_reparacion')->where('id', $o->id)->update([
                'taller_id' => $tallerId,
                'sucursal_id' => $o->sucursal_id ?? $sucursalId,
                'folio' => ++$folio,
                'token' => Str::random(32),
                'pagado' => $o->anticipo,
                'bloqueo_valor' => $o->bloqueo_valor !== null ? Crypt::encryptString($o->bloqueo_valor) : null,
            ]);
        }

        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->unique(['taller_id', 'folio']);
            $table->unique('token');
            $table->index(['sucursal_id', 'estado']);
        });

        Schema::table('fotos_orden', function (Blueprint $table) {
            $table->foreignId('taller_id')->nullable()->after('id')->constrained('talleres')->cascadeOnDelete();
        });
        DB::table('fotos_orden')->whereNull('taller_id')->update(['taller_id' => $tallerId]);

        // ---------------------------------------------------------------- locatarios (mayoreo)

        Schema::create('locatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('whatsapp', 20)->nullable();
            $table->timestamps();
            $table->unique(['taller_id', 'nombre']);
        });

        Schema::create('telefonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('locatario_id')->constrained('locatarios');
            $table->string('prioridad', 10)->default('MEDIA');      // ALTA, MEDIA, BAJA
            $table->date('fecha_recibido');
            $table->string('modelo');
            $table->string('trabajo')->default('');
            $table->string('notas')->default('');
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('pagado', 10, 2)->default(0);
            $table->string('resultado', 12)->default('EN TALLER');  // EN TALLER, LISTO, NO QUEDÓ
            $table->boolean('entregado')->default(false);
            $table->dateTime('fecha_entrega')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sucursal_id', 'resultado', 'entregado']);
        });

        // ---------------------------------------------------------------- caja

        // cada peso que entra a la caja (abonos, anticipos, depósitos a saldo a favor, ventas de mostrador)
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->dateTime('fecha');
            $table->foreignId('telefono_id')->nullable()->constrained('telefonos')->nullOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes_reparacion')->nullOnDelete();
            $table->foreignId('locatario_id')->nullable()->constrained('locatarios')->nullOnDelete();
            $table->string('descripcion');      // quién y qué, se guarda por si se borra el origen
            $table->string('concepto');
            $table->decimal('monto', 10, 2);    // negativo = corrección
            $table->string('metodo', 15)->default('EFECTIVO');   // EFECTIVO, TARJETA, TRANSFERENCIA; el corte solo cuenta efectivo
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sucursal_id', 'fecha']);
        });

        Schema::create('salidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->dateTime('fecha');
            $table->string('concepto');
            $table->string('categoria')->default('');
            $table->decimal('monto', 10, 2);
            $table->string('notas')->default('');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sucursal_id', 'fecha']);
        });

        Schema::create('cortes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->date('fecha');
            $table->decimal('fondo', 10, 2);
            $table->decimal('entradas', 10, 2);
            $table->decimal('salidas', 10, 2);
            $table->decimal('esperado', 10, 2);
            $table->decimal('contado', 10, 2);
            $table->decimal('diferencia', 10, 2);
            $table->string('notas')->default('');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sucursal_id', 'fecha']);
        });

        // saldo a favor: + entra al saldo (depósito), - sale (aplicado a un teléfono o devuelto)
        Schema::create('creditos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->dateTime('fecha');
            $table->foreignId('locatario_id')->constrained('locatarios');
            $table->string('tipo', 12);         // DEPÓSITO, APLICADO, DEVUELTO
            $table->string('concepto');
            $table->decimal('monto', 10, 2);
            $table->foreignId('telefono_id')->nullable()->constrained('telefonos')->nullOnDelete();
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->foreignId('salida_id')->nullable()->constrained('salidas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---------------------------------------------------------------- inventario

        // catálogo del taller (compartido por todas sus sucursales)
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->string('codigo', 60)->default('');
            $table->string('nombre');
            $table->string('categoria')->default('');
            $table->decimal('costo', 10, 2)->default(0);
            $table->decimal('precio', 10, 2)->default(0);
            $table->decimal('precio_locatario', 10, 2)->nullable();   // null = el mismo que al cliente final
            $table->unsignedInteger('minimo')->default(0);            // avisar cuando quede esto o menos
            $table->timestamps();
            $table->index(['taller_id', 'nombre']);
        });

        // cuántas piezas hay de cada producto en cada sucursal
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->integer('cantidad')->default(0);
            $table->timestamps();
            $table->unique(['sucursal_id', 'producto_id']);
        });

        // ---------------------------------------------------------------- punto de venta

        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->unsignedInteger('folio');                     // consecutivo por taller
            $table->string('token', 40)->nullable()->unique();    // enlace público al PDF del ticket
            $table->string('tipo', 12)->default('CLIENTE');       // CLIENTE, LOCATARIO
            $table->foreignId('locatario_id')->nullable()->constrained('locatarios')->nullOnDelete();
            $table->string('cliente_nombre')->default('');
            $table->string('cliente_telefono', 20)->default('');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->decimal('pagado', 10, 2)->default(0);         // lo que ya pagó (al contado o en abonos)
            $table->decimal('devuelto', 10, 2)->default(0);       // valor de lo que regresó
            $table->string('metodo', 15)->default('EFECTIVO');
            $table->decimal('recibido', 10, 2)->nullable();       // con cuánto pagó (efectivo), para el cambio
            $table->string('estatus', 12)->default('PAGADA');     // PAGADA, CRÉDITO, CANCELADA
            $table->dateTime('cancelada_at')->nullable();
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['taller_id', 'folio']);
            $table->index(['sucursal_id', 'created_at']);
        });

        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('nombre');                             // se guarda por si cambia o se borra el producto
            $table->integer('cantidad');
            $table->integer('devuelto')->default(0);              // piezas que regresaron
            $table->decimal('precio', 10, 2);
            $table->decimal('costo', 10, 2)->default(0);          // para calcular la ganancia
            $table->decimal('importe', 10, 2);
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable()->after('orden_id')->constrained('ventas')->nullOnDelete();
        });

        // kárdex: cada entrada, venta, ajuste o traspaso
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('tipo', 20);          // ENTRADA, VENTA, AJUSTE, TRASPASO, CANCELACIÓN, DEVOLUCIÓN
            $table->integer('cantidad');         // + entra, - sale
            $table->integer('existencia');       // cómo quedó después del movimiento
            $table->decimal('importe', 10, 2)->default(0);   // costo de la compra o total de la venta
            $table->string('nota')->default('');
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->foreignId('salida_id')->nullable()->constrained('salidas')->nullOnDelete();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sucursal_id', 'producto_id']);
        });

        Schema::create('ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->constrained('talleres')->cascadeOnDelete();
            $table->string('clave');
            $table->text('valor');
            $table->unique(['taller_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::table('pagos', fn (Blueprint $table) => $table->dropConstrainedForeignId('venta_id'));
        foreach (['ajustes', 'movimientos', 'venta_detalles', 'ventas', 'existencias', 'productos', 'creditos', 'cortes',
            'salidas', 'pagos', 'telefonos', 'locatarios'] as $t) {
            Schema::dropIfExists($t);
        }

        foreach (DB::table('ordenes_reparacion')->whereNotNull('bloqueo_valor')->get(['id', 'bloqueo_valor']) as $o) {
            DB::table('ordenes_reparacion')->where('id', $o->id)->update(['bloqueo_valor' => Crypt::decryptString($o->bloqueo_valor)]);
        }
        foreach (DB::table('equipos')->whereNotNull('patron_desbloqueo')->get(['id', 'patron_desbloqueo']) as $e) {
            DB::table('equipos')->where('id', $e->id)->update(['patron_desbloqueo' => Crypt::decryptString($e->patron_desbloqueo)]);
        }

        Schema::table('fotos_orden', fn (Blueprint $table) => $table->dropConstrainedForeignId('taller_id'));
        Schema::table('ordenes_reparacion', function (Blueprint $table) {
            $table->dropUnique(['taller_id', 'folio']);
            $table->dropUnique(['token']);
            $table->dropIndex(['sucursal_id', 'estado']);
            $table->dropConstrainedForeignId('taller_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['folio', 'token', 'pagado', 'revision', 'observaciones', 'bloqueo_pin']);
        });
        Schema::table('equipos', fn (Blueprint $table) => $table->dropConstrainedForeignId('taller_id'));
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['taller_id', 'telefono']);
            $table->dropConstrainedForeignId('taller_id');
            $table->dropColumn('telefono2');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
            $table->dropConstrainedForeignId('taller_id');
        });
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taller_id');
            $table->dropColumn(['direccion', 'telefono']);
        });
        Schema::dropIfExists('talleres');
    }
};

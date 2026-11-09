<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;

/**
 * Copia los datos de RepaPOS (base "telefonos") a este sistema. La base de origen solo se lee.
 * Toda la copia va en una transacción: si algo no cuadra al final, no queda nada a medias.
 */
class ImportarRepapos extends Command
{
    protected $signature = 'app:importar-repapos
        {--base=telefonos : Base de datos de origen (en el mismo servidor MySQL)}
        {--env= : Ruta del .env de RepaPOS, para descifrar PIN y patrones}
        {--almacen= : Carpeta storage/app/private de RepaPOS, para copiar los logos}
        {--sin-descifrar : Importa aunque algún PIN o patrón no se pueda descifrar (queda sin esos datos y con una nota)}
        {--simular : Hace toda la copia y la verificación, y al final la deshace}';

    protected $description = 'Importar los datos de RepaPOS (talleres, locatarios, caja, inventario, ventas)';

    private Encrypter $origen;

    /** Órdenes cuyo PIN o patrón no se pudo descifrar con la clave disponible. */
    private array $sinDescifrar = [];

    /** @var array<string, array<int, int>> id de origen => id nuevo, por tabla */
    private array $mapa = [];

    /** Mapeo de estatus de una orden. */
    private const ESTADOS = [
        'RECIBIDO' => 'recibido',
        'DIAGNÓSTICO' => 'diagnostico',
        'EN REPARACIÓN' => 'en_reparacion',
        'ESPERA REFACCIÓN' => 'esperando_repuesto',
        'LISTO' => 'reparado',
        'NO QUEDÓ' => 'no_quedo',
        'ENTREGADO' => 'entregado',
    ];

    public function handle(): int
    {
        $this->configurarOrigen();

        if (DB::table('locatarios')->exists() || DB::table('talleres')->whereIn('nombre', $this->src('talleres')->pluck('nombre'))->exists()) {
            $this->error('Ya hay datos de RepaPOS importados. Por seguridad no se importa dos veces.');

            return 1;
        }
        $repetidos = DB::table('users')->whereIn('email', $this->src('users')->pluck('email'))->pluck('email');
        if ($repetidos->isNotEmpty()) {
            $this->error('Estos correos ya existen en este sistema: '.$repetidos->implode(', '));

            return 1;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Superadmin', 'Administrador', 'Empleado'] as $rol) {
            Role::findByName($rol, 'web'); // falla aquí si no se han sembrado los roles
        }

        DB::beginTransaction();
        try {
            $this->copiar();
            $diferencias = $this->verificar();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($diferencias) {
            DB::rollBack();
            $this->error('La verificación encontró diferencias; no se importó nada:');
            foreach ($diferencias as $d) {
                $this->line("  - $d");
            }

            return 1;
        }

        if ($this->sinDescifrar) {
            $this->warn('Órdenes sin PIN/patrón (no se pudo descifrar): '.implode(', ', array_unique($this->sinDescifrar)));
        }

        if ($this->option('simular')) {
            DB::rollBack();
            $this->warn('Simulación correcta: todo cuadra y no se guardó nada.');

            return 0;
        }

        DB::commit();
        $this->copiarLogos();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Importación terminada: todo cuadra con el origen.');

        return 0;
    }

    private function configurarOrigen(): void
    {
        config(['database.connections.repapos' => ['database' => $this->option('base')] + config('database.connections.'.config('database.default'))]);

        $env = $this->option('env') ?: base_path('../../taller/telefonos-web/.env');
        if (! File::exists($env) || ! preg_match('/^APP_KEY=base64:(.+)$/m', File::get($env), $m)) {
            throw new RuntimeException("No se encontró la clave de RepaPOS en $env (usa --env=).");
        }
        $this->origen = new Encrypter(base64_decode(trim($m[1])), 'AES-256-CBC');
    }

    private function src(string $tabla)
    {
        return DB::connection('repapos')->table($tabla)->orderBy(in_array($tabla, ['ajustes'], true) ? 'taller_id' : 'id');
    }

    /** Traduce las llaves foráneas de una fila; un id desconocido es un error, no un dato perdido. */
    private function fk(?int $id, string $tabla): ?int
    {
        if ($id === null) {
            return null;
        }

        return $this->mapa[$tabla][$id] ?? throw new RuntimeException("Referencia a $tabla #$id que no existe en el origen.");
    }

    private function copiar(): void
    {
        foreach ($this->src('talleres')->get() as $t) {
            $this->mapa['talleres'][$t->id] = DB::table('talleres')->insertGetId([
                'nombre' => $t->nombre, 'whatsapp' => $t->whatsapp, 'logo' => $t->logo, 'estado' => $t->estado,
                'prueba_hasta' => $t->prueba_hasta, 'pagado_hasta' => $t->pagado_hasta, 'notas' => $t->notas,
                'created_at' => $t->created_at, 'updated_at' => $t->updated_at,
            ]);
        }

        foreach ($this->src('sucursales')->get() as $s) {
            $this->mapa['sucursales'][$s->id] = DB::table('sucursales')->insertGetId([
                'taller_id' => $this->fk($s->taller_id, 'talleres'), 'nombre' => $s->nombre, 'direccion' => $s->direccion,
                'telefono' => $s->telefono, 'created_at' => $s->created_at, 'updated_at' => $s->updated_at,
            ]);
        }

        $roles = Role::whereIn('name', ['Superadmin', 'Administrador', 'Empleado'])->pluck('id', 'name');
        foreach ($this->src('users')->get() as $u) {
            // el hash de la contraseña se copia tal cual: cada quien entra con la que ya tiene
            $id = DB::table('users')->insertGetId([
                'name' => $u->name, 'email' => $u->email, 'email_verified_at' => $u->email_verified_at, 'password' => $u->password,
                'taller_id' => $this->fk($u->taller_id, 'talleres'), 'sucursal_id' => $this->fk($u->sucursal_id, 'sucursales'),
                'remember_token' => null, 'created_at' => $u->created_at, 'updated_at' => $u->updated_at,
            ]);
            $this->mapa['users'][$u->id] = $id;

            $asignar = [$u->es_admin ? 'Administrador' : 'Empleado'];
            if ($u->es_superadmin) {
                $asignar[] = 'Superadmin';
            }
            foreach ($asignar as $rol) {
                DB::table('model_has_roles')->insert(['role_id' => $roles[$rol], 'model_type' => \App\Models\User::class, 'model_id' => $id]);
            }
        }

        foreach ($this->src('clientes')->get() as $c) {
            $this->mapa['clientes'][$c->id] = DB::table('clientes')->insertGetId([
                'taller_id' => $this->fk($c->taller_id, 'talleres'), 'nombre' => $c->nombre, 'telefono' => $c->telefono, 'telefono2' => $c->telefono2,
                'email' => $c->email !== '' ? $c->email : null, 'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
            ]);
        }

        foreach ($this->src('locatarios')->get() as $l) {
            $this->mapa['locatarios'][$l->id] = DB::table('locatarios')->insertGetId([
                'taller_id' => $this->fk($l->taller_id, 'talleres'), 'nombre' => $l->nombre, 'whatsapp' => $l->whatsapp,
                'created_at' => $l->created_at, 'updated_at' => $l->updated_at,
            ]);
        }

        foreach ($this->src('telefonos')->get() as $t) {
            $this->mapa['telefonos'][$t->id] = DB::table('telefonos')->insertGetId([
                'taller_id' => $this->fk($t->taller_id, 'talleres'), 'sucursal_id' => $this->fk($t->sucursal_id, 'sucursales'),
                'locatario_id' => $this->fk($t->locatario_id, 'locatarios'), 'prioridad' => $t->prioridad, 'fecha_recibido' => $t->fecha_recibido,
                'modelo' => $t->modelo, 'trabajo' => $t->trabajo, 'notas' => $t->notas, 'total' => $t->total, 'pagado' => $t->pagado,
                'resultado' => $t->resultado, 'entregado' => $t->entregado, 'fecha_entrega' => $t->fecha_entrega,
                'user_id' => $this->fk($t->user_id, 'users'), 'created_at' => $t->created_at, 'updated_at' => $t->updated_at,
            ]);
        }

        $this->copiarOrdenes();

        foreach ($this->src('salidas')->get() as $s) {
            $this->mapa['salidas'][$s->id] = DB::table('salidas')->insertGetId([
                'taller_id' => $this->fk($s->taller_id, 'talleres'), 'sucursal_id' => $this->fk($s->sucursal_id, 'sucursales'),
                'fecha' => $s->fecha, 'concepto' => $s->concepto, 'categoria' => $s->categoria, 'monto' => $s->monto, 'notas' => $s->notas,
                'user_id' => $this->fk($s->user_id, 'users'), 'created_at' => $s->created_at, 'updated_at' => $s->updated_at,
            ]);
        }

        foreach ($this->src('cortes')->get() as $c) {
            DB::table('cortes')->insert([
                'taller_id' => $this->fk($c->taller_id, 'talleres'), 'sucursal_id' => $this->fk($c->sucursal_id, 'sucursales'),
                'fecha' => $c->fecha, 'fondo' => $c->fondo, 'entradas' => $c->entradas, 'salidas' => $c->salidas, 'esperado' => $c->esperado,
                'contado' => $c->contado, 'diferencia' => $c->diferencia, 'notas' => $c->notas,
                'user_id' => $this->fk($c->user_id, 'users'), 'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
            ]);
        }

        foreach ($this->src('productos')->get() as $p) {
            $this->mapa['productos'][$p->id] = DB::table('productos')->insertGetId([
                'taller_id' => $this->fk($p->taller_id, 'talleres'), 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'categoria' => $p->categoria,
                'costo' => $p->costo, 'precio' => $p->precio, 'precio_locatario' => $p->precio_locatario, 'minimo' => $p->minimo,
                'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
            ]);
        }

        foreach ($this->src('existencias')->get() as $e) {
            DB::table('existencias')->insert([
                'taller_id' => $this->fk($e->taller_id, 'talleres'), 'sucursal_id' => $this->fk($e->sucursal_id, 'sucursales'),
                'producto_id' => $this->fk($e->producto_id, 'productos'), 'cantidad' => $e->cantidad,
                'created_at' => $e->created_at, 'updated_at' => $e->updated_at,
            ]);
        }

        // ventas y pagos se referencian entre sí: primero las ventas sin pago_id, luego los pagos, y al final se liga
        foreach ($this->src('ventas')->get() as $v) {
            $this->mapa['ventas'][$v->id] = DB::table('ventas')->insertGetId([
                'taller_id' => $this->fk($v->taller_id, 'talleres'), 'sucursal_id' => $this->fk($v->sucursal_id, 'sucursales'),
                'folio' => $v->folio, 'token' => $v->token, 'tipo' => $v->tipo, 'locatario_id' => $this->fk($v->locatario_id, 'locatarios'),
                'cliente_nombre' => $v->cliente_nombre, 'cliente_telefono' => $v->cliente_telefono, 'subtotal' => $v->subtotal,
                'descuento' => $v->descuento, 'total' => $v->total, 'pagado' => $v->pagado, 'devuelto' => $v->devuelto, 'metodo' => $v->metodo,
                'recibido' => $v->recibido, 'estatus' => $v->estatus, 'cancelada_at' => $v->cancelada_at, 'pago_id' => null,
                'user_id' => $this->fk($v->user_id, 'users'), 'created_at' => $v->created_at, 'updated_at' => $v->updated_at,
            ]);
        }

        foreach ($this->src('venta_detalles')->get() as $d) {
            DB::table('venta_detalles')->insert([
                'venta_id' => $this->fk($d->venta_id, 'ventas'), 'producto_id' => $this->fk($d->producto_id, 'productos'), 'nombre' => $d->nombre,
                'cantidad' => $d->cantidad, 'devuelto' => $d->devuelto, 'precio' => $d->precio, 'costo' => $d->costo, 'importe' => $d->importe,
            ]);
        }

        foreach ($this->src('pagos')->get() as $p) {
            $this->mapa['pagos'][$p->id] = DB::table('pagos')->insertGetId([
                'taller_id' => $this->fk($p->taller_id, 'talleres'), 'sucursal_id' => $this->fk($p->sucursal_id, 'sucursales'), 'fecha' => $p->fecha,
                'telefono_id' => $this->fk($p->telefono_id, 'telefonos'), 'orden_id' => $this->fk($p->orden_id, 'ordenes'),
                'venta_id' => $this->fk($p->venta_id, 'ventas'), 'locatario_id' => $this->fk($p->locatario_id, 'locatarios'),
                'descripcion' => $p->descripcion, 'concepto' => $p->concepto, 'monto' => $p->monto, 'metodo' => $p->metodo,
                'user_id' => $this->fk($p->user_id, 'users'), 'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
            ]);
        }

        foreach ($this->src('ventas')->whereNotNull('pago_id')->get() as $v) {
            DB::table('ventas')->where('id', $this->fk($v->id, 'ventas'))->update(['pago_id' => $this->fk($v->pago_id, 'pagos')]);
        }

        foreach ($this->src('creditos')->get() as $c) {
            DB::table('creditos')->insert([
                'taller_id' => $this->fk($c->taller_id, 'talleres'), 'fecha' => $c->fecha, 'locatario_id' => $this->fk($c->locatario_id, 'locatarios'),
                'tipo' => $c->tipo, 'concepto' => $c->concepto, 'monto' => $c->monto, 'telefono_id' => $this->fk($c->telefono_id, 'telefonos'),
                'pago_id' => $this->fk($c->pago_id, 'pagos'), 'salida_id' => $this->fk($c->salida_id, 'salidas'),
                'user_id' => $this->fk($c->user_id, 'users'), 'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
            ]);
        }

        foreach ($this->src('movimientos')->get() as $m) {
            DB::table('movimientos')->insert([
                'taller_id' => $this->fk($m->taller_id, 'talleres'), 'sucursal_id' => $this->fk($m->sucursal_id, 'sucursales'),
                'producto_id' => $this->fk($m->producto_id, 'productos'), 'tipo' => $m->tipo, 'cantidad' => $m->cantidad, 'existencia' => $m->existencia,
                'importe' => $m->importe, 'nota' => $m->nota, 'pago_id' => $this->fk($m->pago_id, 'pagos'), 'salida_id' => $this->fk($m->salida_id, 'salidas'),
                'venta_id' => $this->fk($m->venta_id, 'ventas'), 'user_id' => $this->fk($m->user_id, 'users'),
                'created_at' => $m->created_at, 'updated_at' => $m->updated_at,
            ]);
        }

        foreach ($this->src('ajustes')->get() as $a) {
            DB::table('ajustes')->insert(['taller_id' => $this->fk($a->taller_id, 'talleres'), 'clave' => $a->clave, 'valor' => $a->valor]);
        }
    }

    /** Una orden de RepaPOS trae el equipo incluido; aquí se separa en su propio registro. */
    private function copiarOrdenes(): void
    {
        foreach ($this->src('ordenes')->get() as $o) {
            $tallerId = $this->fk($o->taller_id, 'talleres');
            $clienteId = $this->fk($o->cliente_id, 'clientes');
            $estado = self::ESTADOS[$o->estatus] ?? throw new RuntimeException("Estatus desconocido en la orden {$o->id}: {$o->estatus}");

            $equipoId = DB::table('equipos')->insertGetId([
                'taller_id' => $tallerId, 'cliente_id' => $clienteId, 'tipo' => 'celular', 'marca' => $o->marca, 'modelo' => $o->modelo,
                'imei' => $o->imei !== '' ? $o->imei : null, 'color' => $o->color !== '' ? $o->color : null,
                'created_at' => $o->created_at, 'updated_at' => $o->updated_at,
            ]);

            // PIN y patrón vienen cifrados con la clave de RepaPOS: se vuelven a cifrar con la de este sistema
            $pin = $this->descifrar($o->pin, $o);
            $patron = $this->descifrar($o->patron, $o);
            $notas = in_array($o->id, $this->sinDescifrar, true)
                ? 'El PIN/patrón de esta orden no se pudo migrar: en RepaPOS se cifró con otra clave (APP_KEY).' : null;
            [$tipo, $valor, $adicional] = match (true) {
                $patron !== null => ['patron', $patron, $pin],
                $pin !== null => ['pin', $pin, null],
                default => ['ninguno', null, null],
            };

            $promesa = $o->fecha_promesa;
            $this->mapa['ordenes'][$o->id] = DB::table('ordenes_reparacion')->insertGetId([
                'taller_id' => $tallerId,
                'sucursal_id' => $this->fk($o->sucursal_id, 'sucursales'),
                // el número de ticket es único en todo el sistema; el folio N° 0001 sigue siendo por taller
                'numero_orden' => 'REP-'.$tallerId.'-'.str_pad((string) $o->folio, 4, '0', STR_PAD_LEFT),
                'folio' => $o->folio,
                'token' => $o->token,   // los enlaces que ya tienen los clientes siguen sirviendo
                'cliente_id' => $clienteId,
                'equipo_id' => $equipoId,
                'user_id' => $this->fk($o->user_id, 'users'),
                'estado' => $estado,
                'prioridad' => 'media',
                'tipo_servicio' => 'reparacion',
                'bloqueo_tipo' => $tipo,
                'bloqueo_valor' => $valor !== null ? Crypt::encryptString($valor) : null,
                'bloqueo_pin' => $adicional !== null ? Crypt::encryptString($adicional) : null,
                'revision' => $o->revision,
                'falla_reportada' => $o->fallas,
                'observaciones' => $o->observaciones,
                'diagnostico' => $o->diagnostico,
                'costo_estimado' => $o->presupuesto,
                'anticipo' => 0,
                'pagado' => $o->pagado,
                'metodo_pago' => 'efectivo',
                'visible_cliente' => true,
                'fecha_recepcion' => $o->created_at,
                'fecha_entrega_estimada' => $promesa,
                'estimado_listo_at' => $promesa ? $promesa.' 18:00:00' : null,
                'fecha_entrega_real' => $o->fecha_entrega,
                'notas' => $notas,
                'created_at' => $o->created_at,
                'updated_at' => $o->updated_at,
            ]);
        }
    }

    private function descifrar(?string $cifrado, object $orden): ?string
    {
        if ($cifrado === null) {
            return null;
        }
        try {
            return $this->origen->decryptString($cifrado);
        } catch (DecryptException $e) {
            if (! $this->option('sin-descifrar')) {
                throw new RuntimeException("No se pudo descifrar el PIN/patrón de la orden folio {$orden->folio}: la clave (APP_KEY) no es la original. Usa --env= con el .env original, o --sin-descifrar para migrar sin ese dato.");
            }
            $this->sinDescifrar[] = $orden->id;

            return null;
        }
    }

    /** Compara el origen con lo copiado: cantidades de cada tabla, dinero por taller y deuda por locatario. */
    private function verificar(): array
    {
        $difs = [];
        $mapaTablas = ['ordenes' => 'ordenes_reparacion'];
        $tablas = ['talleres', 'sucursales', 'locatarios', 'telefonos', 'clientes', 'ordenes', 'pagos', 'salidas', 'cortes', 'creditos',
            'productos', 'existencias', 'movimientos', 'ventas', 'venta_detalles', 'ajustes', 'users'];
        $filas = [];

        foreach ($tablas as $t) {
            $destino = $mapaTablas[$t] ?? $t;
            $o = DB::connection('repapos')->table($t)->count();
            $query = DB::table($destino);
            // en el destino pueden existir filas previas (taller de pruebas): solo cuentan las de los talleres importados
            if (in_array($destino, ['talleres'], true)) {
                $query->whereIn('id', $this->mapa['talleres']);
            } elseif ($t !== 'venta_detalles') {
                $query->whereIn('taller_id', $this->mapa['talleres']);
            }
            $d = $query->count();
            $filas[] = [$t, $o, $d, $o === $d ? 'OK' : 'DIFERENTE'];
            if ($o !== $d) {
                $difs[] = "$t: origen $o, copiados $d";
            }
        }
        $this->table(['Tabla', 'Origen', 'Copiados', ''], $filas);

        $sumas = [['pagos', 'monto'], ['salidas', 'monto'], ['telefonos', 'total'], ['telefonos', 'pagado'], ['creditos', 'monto'], ['ventas', 'total'], ['ventas', 'pagado'], ['cortes', 'contado']];
        $filas = [];
        foreach ($sumas as [$t, $c]) {
            foreach ($this->mapa['talleres'] as $viejo => $nuevo) {
                $o = round((float) DB::connection('repapos')->table($t)->where('taller_id', $viejo)->sum($c), 2);
                $d = round((float) DB::table($t)->where('taller_id', $nuevo)->sum($c), 2);
                $filas[] = ["$t.$c", "#$viejo", $o, $d, $o == $d ? 'OK' : 'DIFERENTE'];
                if ($o != $d) {
                    $difs[] = "$t.$c del taller #$viejo: origen $o, copiado $d";
                }
            }
        }
        $this->table(['Dinero', 'Taller', 'Origen', 'Copiado', ''], $filas);

        // lo que debe cada locatario es el dato más delicado: se compara uno por uno
        $deudas = 'SUM(CASE WHEN resultado <> ? AND total > pagado THEN total - pagado ELSE 0 END)';
        foreach ($this->mapa['locatarios'] ?? [] as $viejo => $nuevo) {
            $o = round((float) DB::connection('repapos')->table('telefonos')->where('locatario_id', $viejo)->selectRaw("$deudas as s", ['NO QUEDÓ'])->value('s'), 2);
            $d = round((float) DB::table('telefonos')->where('locatario_id', $nuevo)->selectRaw("$deudas as s", ['NO QUEDÓ'])->value('s'), 2);
            if ($o != $d) {
                $difs[] = "Deuda del locatario #$viejo: origen $o, copiado $d";
            }
        }
        $this->line('Deuda comparada por locatario: '.count($this->mapa['locatarios'] ?? []).' revisados.');

        // PIN y patrón deben poder leerse con la clave de este sistema
        foreach ($this->mapa['ordenes'] ?? [] as $viejo => $nuevo) {
            $o = DB::connection('repapos')->table('ordenes')->find($viejo);
            $n = DB::table('ordenes_reparacion')->find($nuevo);
            if (in_array($viejo, $this->sinDescifrar, true)) {
                continue;
            }
            $esperado = $o->patron !== null ? $this->origen->decryptString($o->patron) : ($o->pin !== null ? $this->origen->decryptString($o->pin) : null);
            $copiado = $n->bloqueo_valor !== null ? Crypt::decryptString($n->bloqueo_valor) : null;
            if ($esperado !== $copiado) {
                $difs[] = "PIN/patrón de la orden #$viejo no coincide";
            }
        }

        return $difs;
    }

    private function copiarLogos(): void
    {
        $almacen = $this->option('almacen') ?: base_path('../../taller/telefonos-web/storage/app/private');
        foreach (DB::table('talleres')->whereIn('id', $this->mapa['talleres'])->whereNotNull('logo')->get() as $t) {
            $origen = "$almacen/{$t->logo}";
            if (! File::exists($origen)) {
                $this->warn("Logo de {$t->nombre} no encontrado en $origen");

                continue;
            }
            $destino = storage_path("app/private/{$t->logo}");
            File::ensureDirectoryExists(dirname($destino));
            File::copy($origen, $destino);
        }
    }
}

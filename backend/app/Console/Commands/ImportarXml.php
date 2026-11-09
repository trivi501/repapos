<?php

namespace App\Console\Commands;

use App\Models\Credito;
use App\Models\Taller;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Pasa los datos de la versión de escritorio (telefonos_locatarios.xml) a un taller de la web. */
class ImportarXml extends Command
{
    protected $signature = 'app:importar-xml {archivo : Ruta de telefonos_locatarios.xml}
                            {--taller= : Número del taller al que se importa (ver app:talleres)}
                            {--forzar : Borrar antes los datos que ya tenga ese taller}';
    protected $description = 'Importar los datos de la app de escritorio a un taller';

    private int $tallerId;
    private int $sucursalId;
    private array $locatarios = [];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');
        if (! is_file($ruta) || ($xml = simplexml_load_file($ruta)) === false) {
            $this->error("No se pudo leer el archivo $ruta");
            return 1;
        }
        $taller = Taller::find($this->option('taller'));
        if (! $taller) {
            $this->error('Indica el taller con --taller=NÚMERO (usa "php artisan app:talleres" para verlos).');
            return 1;
        }
        $this->tallerId = $taller->id;
        if (DB::table('telefonos')->where('taller_id', $taller->id)->exists() && ! $this->option('forzar')) {
            $this->error("El taller \"{$taller->nombre}\" ya tiene teléfonos. Usa --forzar para borrarlos e importar de nuevo.");
            return 1;
        }

        // todo lo del archivo de escritorio entra a la primera sucursal (Matriz)
        $this->sucursalId = (int) DB::table('sucursales')->where('taller_id', $taller->id)->orderBy('id')->value('id');

        DB::transaction(function () use ($xml) {
            $t = $this->tallerId;
            $suc = $this->sucursalId;
            if ($this->option('forzar')) {
                // borra lo de locatarios y la caja; respeta órdenes de clientes, ventas e inventario
                DB::table('creditos')->where('taller_id', $t)->delete();
                DB::table('pagos')->where('taller_id', $t)->whereNull('orden_id')
                    ->whereNotIn('id', DB::table('movimientos')->whereNotNull('pago_id')->select('pago_id'))->delete();
                DB::table('salidas')->where('taller_id', $t)
                    ->whereNotIn('id', DB::table('movimientos')->whereNotNull('salida_id')->select('salida_id'))->delete();
                foreach (['cortes', 'telefonos', 'locatarios'] as $tabla) {
                    DB::table($tabla)->where('taller_id', $t)->delete();
                }
            }
            $ahora = now();
            // los números del archivo de escritorio se cambian por los nuevos de la base de datos
            $telefonos = $pagos = $salidas = [];

            foreach ($xml->Contacto as $c) {
                $this->locatario((string) $c->Locatario, preg_replace('/\D/', '', (string) $c->WhatsApp));
            }

            foreach ($xml->Telefono as $x) {
                $telefonos[(int) $x->Id] = DB::table('telefonos')->insertGetId([
                    'taller_id' => $t, 'sucursal_id' => $suc,
                    'locatario_id' => $this->locatario((string) $x->Locatario),
                    'prioridad' => in_array((string) $x->Prioridad, ['ALTA', 'MEDIA', 'BAJA'], true) ? (string) $x->Prioridad : 'MEDIA',
                    'fecha_recibido' => $this->fecha((string) $x->Fecha)->toDateString(),
                    'modelo' => (string) $x->Modelo,
                    'trabajo' => (string) $x->Trabajo,
                    'notas' => (string) $x->Notas,
                    'total' => (float) $x->Total,
                    'pagado' => (float) $x->Pagado,
                    'resultado' => in_array((string) $x->Resultado, ['EN TALLER', 'LISTO', 'NO QUEDÓ'], true)
                        ? (string) $x->Resultado : ((string) $x->Entregado === '1' ? 'LISTO' : 'EN TALLER'),
                    'entregado' => (string) $x->Entregado === '1',
                    'fecha_entrega' => (string) $x->FechaEntrega !== '' ? $this->fecha((string) $x->FechaEntrega) : null,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            foreach ($xml->Pago as $p) {
                $pagos[(int) $p->Id] = DB::table('pagos')->insertGetId([
                    'taller_id' => $t, 'sucursal_id' => $suc,
                    'fecha' => $this->fecha((string) $p->Fecha),
                    'telefono_id' => $telefonos[(int) $p->TelefonoId] ?? null,
                    'locatario_id' => $this->locatario((string) $p->Locatario),
                    'descripcion' => $p->Locatario . ' - ' . $p->Modelo,
                    'concepto' => (string) $p->Concepto,
                    'monto' => (float) $p->Monto,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            foreach ($xml->Salida as $s) {
                $salidas[(int) $s->Id] = DB::table('salidas')->insertGetId([
                    'taller_id' => $t, 'sucursal_id' => $suc,
                    'fecha' => $this->fecha((string) $s->Fecha),
                    'concepto' => (string) $s->Concepto,
                    'categoria' => (string) $s->Categoria,
                    'monto' => (float) $s->Monto,
                    'notas' => (string) $s->Notas,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            foreach ($xml->Corte as $c) {
                $hecho = (string) $c->Hecho !== '' ? $this->fecha((string) $c->Hecho) : $ahora;
                DB::table('cortes')->insert([
                    'taller_id' => $t, 'sucursal_id' => $suc,
                    'fecha' => $this->fecha((string) $c->Fecha)->toDateString(),
                    'fondo' => (float) $c->Fondo, 'entradas' => (float) $c->Entradas, 'salidas' => (float) $c->Salidas,
                    'esperado' => (float) $c->Esperado, 'contado' => (float) $c->Contado, 'diferencia' => (float) $c->Diferencia,
                    'notas' => (string) $c->Notas,
                    'created_at' => $hecho, 'updated_at' => $hecho,
                ]);
            }

            foreach ($xml->Credito as $c) {
                $tipo = (string) $c->Tipo;
                $ref = (int) $c->Ref;
                DB::table('creditos')->insert([
                    'taller_id' => $t,
                    'fecha' => $this->fecha((string) $c->Fecha),
                    'locatario_id' => $this->locatario((string) $c->Locatario),
                    'tipo' => $tipo,
                    'concepto' => (string) $c->Concepto,
                    'monto' => (float) $c->Monto,
                    'telefono_id' => $telefonos[(int) $c->TelefonoId] ?? null,
                    'pago_id' => $tipo === Credito::DEPOSITO ? ($pagos[$ref] ?? null) : null,
                    'salida_id' => $tipo === Credito::DEVUELTO ? ($salidas[$ref] ?? null) : null,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            // igual que la app de escritorio: lo pagado que no tenga cobro registrado cuenta como cobrado el día que se recibió
            foreach (DB::table('telefonos')->where('taller_id', $t)->get() as $tel) {
                $registrado = (float) DB::table('pagos')->where('telefono_id', $tel->id)->sum('monto')
                    - (float) DB::table('creditos')->where('telefono_id', $tel->id)->where('tipo', Credito::APLICADO)->sum('monto');
                $dif = round((float) $tel->pagado - $registrado, 2);
                if ($dif > 0) {
                    $nombre = DB::table('locatarios')->where('id', $tel->locatario_id)->value('nombre');
                    DB::table('pagos')->insert([
                        'taller_id' => $t, 'sucursal_id' => $suc, 'fecha' => Carbon::parse($tel->fecha_recibido)->setTime(12, 0), 'telefono_id' => $tel->id,
                        'locatario_id' => $tel->locatario_id, 'descripcion' => $nombre . ' - ' . $tel->modelo,
                        'concepto' => 'Pago anterior', 'monto' => $dif, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
            }
        });

        $cuenta = fn ($tabla) => DB::table($tabla)->where('taller_id', $this->tallerId)->count();
        $this->info(sprintf('Importado a "%s": %d locatarios, %d teléfonos, %d cobros, %d salidas, %d cortes, %d movimientos de saldo a favor.',
            $taller->nombre, $cuenta('locatarios'), $cuenta('telefonos'), $cuenta('pagos'), $cuenta('salidas'), $cuenta('cortes'), $cuenta('creditos')));
        return 0;
    }

    /** Id del locatario dentro del taller (lo crea si no existe); si trae WhatsApp, lo guarda. */
    private function locatario(string $nombre, string $whatsapp = ''): int
    {
        $nombre = trim($nombre) === '' ? '(sin nombre)' : trim($nombre);
        $clave = mb_strtolower($nombre);
        if (! isset($this->locatarios[$clave])) {
            $this->locatarios[$clave] = DB::table('locatarios')->insertGetId([
                'taller_id' => $this->tallerId, 'nombre' => $nombre, 'whatsapp' => $whatsapp ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($whatsapp !== '') {
            DB::table('locatarios')->where('id', $this->locatarios[$clave])->update(['whatsapp' => $whatsapp]);
        }
        return $this->locatarios[$clave];
    }

    private function fecha(string $s): Carbon
    {
        return Carbon::parse($s)->setTimezone(config('app.timezone'));
    }
}

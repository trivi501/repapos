<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Respaldo de los datos en un archivo JSON comprimido (funciona igual con MySQL/MariaDB o SQLite).
 * - Completo (todos los talleres): lo hace el servidor cada noche.
 * - De un solo taller: lo descarga el administrador de ese taller desde el menú, solo con sus datos.
 */
class Respaldo
{
    public const TABLAS = ['sucursales', 'users', 'locatarios', 'telefonos', 'clientes', 'equipos', 'ordenes_reparacion', 'fotos_orden',
        'pagos', 'salidas', 'cortes', 'creditos', 'productos', 'existencias', 'movimientos', 'ventas', 'ajustes'];

    public const DIAS = 60;

    public static function carpeta(): string
    {
        return storage_path('app/respaldos');
    }

    /** Devuelve la ruta del archivo creado. Con $tallerId solo incluye los datos de ese taller. */
    public static function crear(?int $tallerId = null): string
    {
        $carpeta = $tallerId ? static::carpeta().'/talleres' : static::carpeta();
        File::ensureDirectoryExists($carpeta);

        $datos = ['creado' => now()->toIso8601String(), 'taller_id' => $tallerId, 'tablas' => []];
        $datos['tablas']['talleres'] = DB::table('talleres')->when($tallerId, fn ($q) => $q->where('id', $tallerId))->get();
        foreach (static::TABLAS as $t) {
            $q = DB::table($t)->when($tallerId, fn ($q) => $q->where('taller_id', $tallerId));
            // el administrador descarga este archivo: no debe llevar los hashes de contraseña
            $datos['tablas'][$t] = $t === 'users' && $tallerId
                ? $q->get()->map(fn ($u) => collect($u)->except(['password', 'remember_token']))
                : $q->get();
        }
        // los renglones de venta no llevan taller_id: van con sus ventas
        $datos['tablas']['venta_detalles'] = DB::table('venta_detalles')
            ->when($tallerId, fn ($q) => $q->whereIn('venta_id', DB::table('ventas')->where('taller_id', $tallerId)->select('id')))->get();
        $nombre = ($tallerId ? "taller{$tallerId}_" : 'respaldo_').now()->format('Y-m-d_His').'.json.gz';
        $ruta = "$carpeta/$nombre";
        file_put_contents($ruta, gzencode(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 9));

        foreach (File::glob("$carpeta/*.json.gz") as $viejo) {
            if (filemtime($viejo) < now()->subDays(static::DIAS)->getTimestamp()) {
                File::delete($viejo);
            }
        }

        return $ruta;
    }
}

<?php

use App\Http\Controllers\Admin\TallerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\CorteController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\FotoOrdenController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\OrdenReparacionController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\ResumenController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaldoFavorController;
use App\Http\Controllers\SalidaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\SuscripcionController;
use App\Http\Controllers\TelefonoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\WhatsAppApiController;
use App\Support\Respaldo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/registro', [RegistroController::class, 'store'])->middleware('throttle:5,1');
Route::get('/producto', [RegistroController::class, 'producto']);

// Consulta pública del cliente: por folio (sección 08) o con el enlace de su comprobante.
Route::get('consulta/{folio}', [ConsultaController::class, 'show'])->middleware('throttle:20,1');
Route::get('publico/orden/{token}', [ConsultaController::class, 'porToken'])->middleware('throttle:30,1');
Route::get('logo/{taller}', [ConfiguracionController::class, 'logo'])->name('taller.logo');
// PDF que se mandan por WhatsApp (el enlace lleva un código largo imposible de adivinar)
Route::get('orden/{token}/pdf', [OrdenReparacionController::class, 'pdfPublico'])->name('orden.pdf')->middleware('throttle:30,1');
Route::get('venta/{token}/pdf', [VentaController::class, 'pdfPublico'])->name('venta.pdf')->middleware('throttle:30,1');

// Subida de fotos desde el celular mediante QR: enlaces firmados y temporales, sin sesión.
Route::middleware('signed:relative')->group(function () {
    Route::get('movil/fotos/{lote}/{categoria}', [FotoOrdenController::class, 'vistaMovil'])->name('fotos.movil');
    Route::post('movil/fotos/{lote}/{categoria}', [FotoOrdenController::class, 'subirMovil'])
        ->middleware('throttle:60,1')
        ->name('fotos.movil.subir');
});

Route::middleware('auth:sanctum')->group(function () {
    // siempre accesibles, aunque la suscripción esté vencida
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/perfil', [PerfilController::class, 'update']);
    Route::get('/suscripcion', [SuscripcionController::class, 'show']);

    // dueño del sistema
    Route::middleware('permission:talleres.administrar')->prefix('admin')->group(function () {
        Route::get('talleres', [TallerController::class, 'index']);
        Route::patch('talleres/{taller}', [TallerController::class, 'update']);
    });

    Route::middleware('suscripcion')->group(function () {
        // sin middleware de permiso: entrega solo las gráficas que el usuario puede ver
        Route::get('panel', [PanelController::class, 'index']);

        Route::get('clientes',[ClienteController::class, 'index'])->middleware('permission:clientes.ver');
        Route::get('clientes/{cliente}', [ClienteController::class, 'show'])->middleware('permission:clientes.ver');
        Route::post('clientes', [ClienteController::class, 'store'])->middleware('permission:clientes.crear');
        Route::put('clientes/{cliente}', [ClienteController::class, 'update'])->middleware('permission:clientes.editar');
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])->middleware('permission:clientes.eliminar');

        Route::get('equipos', [EquipoController::class, 'index'])->middleware('permission:equipos.ver');
        Route::get('equipos/{equipo}', [EquipoController::class, 'show'])->middleware('permission:equipos.ver');
        Route::post('equipos', [EquipoController::class, 'store'])->middleware('permission:equipos.crear');
        Route::put('equipos/{equipo}', [EquipoController::class, 'update'])->middleware('permission:equipos.editar');
        Route::delete('equipos/{equipo}', [EquipoController::class, 'destroy'])->middleware('permission:equipos.eliminar');

        Route::get('fotos', [FotoOrdenController::class, 'index'])->middleware('permission:ordenes.ver');
        Route::post('fotos', [FotoOrdenController::class, 'store'])->middleware('permission:ordenes.crear|ordenes.editar');
        Route::delete('fotos/{foto}', [FotoOrdenController::class, 'destroy'])->middleware('permission:ordenes.crear|ordenes.editar');
        Route::get('fotos-enlace-movil', [FotoOrdenController::class, 'enlaceMovil'])->middleware('permission:ordenes.crear|ordenes.editar');

        Route::get('ordenes-tecnicos', [OrdenReparacionController::class, 'tecnicos'])->middleware('permission:ordenes.ver');
        Route::get('ordenes', [OrdenReparacionController::class, 'index'])->middleware('permission:ordenes.ver');
        Route::get('ordenes/{orden}', [OrdenReparacionController::class, 'show'])->middleware('permission:ordenes.ver');
        Route::get('ordenes/{orden}/recibo', [OrdenReparacionController::class, 'recibo'])->middleware('permission:ordenes.ver');
        Route::get('ordenes/{orden}/pdf', [OrdenReparacionController::class, 'pdf'])->middleware('permission:ordenes.ver');
        Route::post('ordenes', [OrdenReparacionController::class, 'store'])->middleware('permission:ordenes.crear');
        Route::post('ordenes/{orden}/accion', [OrdenReparacionController::class, 'accion'])->middleware('permission:ordenes.editar|ordenes.cambiar_estado|ordenes.cobrar');
        Route::put('ordenes/{orden}', [OrdenReparacionController::class, 'update'])->middleware('permission:ordenes.editar|ordenes.cambiar_estado');
        Route::delete('ordenes/{orden}', [OrdenReparacionController::class, 'destroy'])->middleware('permission:ordenes.eliminar');

        // teléfonos de locatarios
        Route::get('telefonos/exportar', [TelefonoController::class, 'exportar'])->middleware('permission:telefonos.ver');
        Route::get('telefonos', [TelefonoController::class, 'index'])->middleware('permission:telefonos.ver');
        Route::get('telefonos/{telefono}', [TelefonoController::class, 'show'])->middleware('permission:telefonos.ver');
        Route::post('telefonos', [TelefonoController::class, 'store'])->middleware('permission:telefonos.crear');
        Route::post('telefonos/{telefono}/accion', [TelefonoController::class, 'accion'])->middleware('permission:telefonos.editar|telefonos.cobrar');
        Route::put('telefonos/{telefono}', [TelefonoController::class, 'update'])->middleware('permission:telefonos.editar');
        Route::delete('telefonos/{telefono}', [TelefonoController::class, 'destroy'])->middleware('permission:telefonos.eliminar');

        Route::get('resumen', [ResumenController::class, 'index'])->middleware('permission:resumen.ver');

        Route::get('saldos', [SaldoFavorController::class, 'index'])->middleware('permission:saldos.ver');
        Route::middleware('permission:saldos.gestionar')->group(function () {
            Route::post('saldos/deposito', [SaldoFavorController::class, 'deposito']);
            Route::post('saldos/{locatario}/aplicar', [SaldoFavorController::class, 'aplicar']);
            Route::post('saldos/{locatario}/devolver', [SaldoFavorController::class, 'devolver']);
            Route::delete('saldos/movimiento/{credito}', [SaldoFavorController::class, 'eliminar']);
        });

        // inventario
        Route::get('inventario', [InventarioController::class, 'index'])->middleware('permission:inventario.ver');
        Route::get('inventario/{inventario}', [InventarioController::class, 'show'])->middleware('permission:inventario.ver');
        Route::get('inventario/{producto}/kardex', [InventarioController::class, 'kardex'])->middleware('permission:inventario.ver');
        Route::post('inventario', [InventarioController::class, 'store'])->middleware('permission:inventario.crear');
        Route::post('inventario/{producto}/movimiento', [InventarioController::class, 'movimiento'])->middleware('permission:inventario.movimiento');
        Route::put('inventario/{inventario}', [InventarioController::class, 'update'])->middleware('permission:inventario.editar');
        Route::delete('inventario/{inventario}', [InventarioController::class, 'destroy'])->middleware('permission:inventario.eliminar');

        // punto de venta
        Route::get('ventas', [VentaController::class, 'index'])->middleware('permission:ventas.ver');
        Route::get('ventas/catalogo', [VentaController::class, 'catalogo'])->middleware('permission:ventas.crear');
        Route::get('ventas/{venta}', [VentaController::class, 'show'])->middleware('permission:ventas.ver');
        Route::get('ventas/{venta}/ticket', [VentaController::class, 'ticket'])->middleware('permission:ventas.ver');
        Route::get('ventas/{venta}/pdf', [VentaController::class, 'pdf'])->middleware('permission:ventas.ver');
        Route::post('ventas', [VentaController::class, 'store'])->middleware('permission:ventas.crear');
        Route::post('ventas/{venta}/abonar', [VentaController::class, 'abonar'])->middleware('permission:ventas.cobrar');
        Route::post('ventas/{venta}/devolver', [VentaController::class, 'devolver'])->middleware('permission:ventas.devolver');
        Route::post('ventas/{venta}/cancelar', [VentaController::class, 'cancelar'])->middleware('permission:ventas.cancelar');

        // caja
        Route::get('salidas', [SalidaController::class, 'index'])->middleware('permission:salidas.ver');
        Route::post('salidas', [SalidaController::class, 'store'])->middleware('permission:salidas.crear');
        Route::put('salidas/{salida}', [SalidaController::class, 'update'])->middleware('permission:salidas.editar');
        Route::delete('salidas/{salida}', [SalidaController::class, 'destroy'])->middleware('permission:salidas.eliminar');

        Route::get('cortes', [CorteController::class, 'index'])->middleware('permission:cortes.ver');
        Route::get('cortes/historial', [CorteController::class, 'historial'])->middleware('permission:cortes.ver');
        Route::get('cortes/resumen', [CorteController::class, 'resumen'])->middleware('permission:cortes.ver');
        Route::get('cortes/exportar', [CorteController::class, 'exportar'])->middleware('permission:cortes.ver');
        Route::post('cortes', [CorteController::class, 'store'])->middleware('permission:cortes.guardar');

        Route::get('configuracion', [ConfiguracionController::class, 'edit'])->middleware('permission:configuracion.ver');
        Route::post('configuracion', [ConfiguracionController::class, 'update'])->middleware('permission:configuracion.editar');

        // API de WhatsApp Cloud (Meta)
        Route::get('whatsapp-api', [WhatsAppApiController::class, 'show'])->middleware('permission:configuracion.ver');
        Route::put('whatsapp-api', [WhatsAppApiController::class, 'update'])->middleware('permission:configuracion.editar');
        Route::post('whatsapp-api/probar', [WhatsAppApiController::class, 'probar'])->middleware('permission:configuracion.ver');
        Route::post('whatsapp/enviar', [WhatsAppApiController::class, 'enviar'])->middleware('throttle:30,1');

        // administrador de cada taller
        Route::get('sucursales', [SucursalController::class, 'index'])->middleware('permission:ordenes.ver|sucursales.ver');
        Route::post('sucursales', [SucursalController::class, 'store'])->middleware('permission:sucursales.crear');
        Route::put('sucursales/{sucursal}', [SucursalController::class, 'update'])->middleware('permission:sucursales.editar');
        Route::delete('sucursales/{sucursal}', [SucursalController::class, 'destroy'])->middleware('permission:sucursales.eliminar');

        Route::get('usuarios', [UserController::class, 'index'])->middleware('permission:usuarios.ver');
        Route::get('usuarios/{user}', [UserController::class, 'show'])->middleware('permission:usuarios.ver');
        Route::post('usuarios', [UserController::class, 'store'])->middleware('permission:usuarios.crear');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->middleware('permission:usuarios.editar');
        Route::delete('usuarios/{user}', [UserController::class, 'destroy'])->middleware('permission:usuarios.eliminar');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.ver');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.ver');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.crear');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.editar');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.eliminar');
        Route::get('permisos', [PermissionController::class, 'index'])->middleware('permission:roles.ver');

        Route::get('respaldo', fn (Request $r) => response()->download(Respaldo::crear($r->user()->taller_id))->deleteFileAfterSend())
            ->middleware('permission:respaldo.descargar');
    });
});

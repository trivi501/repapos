<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Corte;
use App\Models\Credito;
use App\Models\Equipo;
use App\Models\Locatario;
use App\Models\OrdenReparacion;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\Sucursal;
use App\Models\Taller;
use App\Models\Telefono;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepaposTest extends TestCase
{
    use RefreshDatabase;

    private Taller $tallerA;

    private Taller $tallerB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tallerA = Taller::create(['nombre' => 'Taller A', 'estado' => Taller::CORTESIA]);
        $this->tallerB = Taller::create(['nombre' => 'Taller B', 'estado' => Taller::CORTESIA]);
        $this->adminA = $this->usuario($this->tallerA, 'Administrador');
        $this->adminB = $this->usuario($this->tallerB, 'Administrador');
    }

    private function usuario(Taller $t, string $rol): User
    {
        $u = User::factory()->create(['taller_id' => $t->id]);
        $u->assignRole($rol);

        return $u;
    }

    private function comoTaller(User $u): void
    {
        // cada "petición" del test debe volver a calcular la sucursal
        request()->attributes->remove('sucursal_id');
        Sanctum::actingAs($u);
    }

    private function telefonoDe(User $u, array $datos = []): Telefono
    {
        $this->comoTaller($u);
        $r = $this->postJson('/api/telefonos', $datos + [
            'locatario' => 'Don Pepe', 'prioridad' => 'MEDIA', 'fecha_recibido' => '2026-11-01', 'modelo' => 'MOTO G60',
            'trabajo' => 'PANTALLA', 'total' => 500, 'pagado' => 100, 'resultado' => 'EN TALLER',
        ])->assertCreated();

        return Telefono::withoutGlobalScopes()->findOrFail($r->json('telefono.id'));
    }

    private function productoDe(User $u, int $hay = 10, float $precio = 100, ?float $mayoreo = 80, float $costo = 60): Producto
    {
        $this->comoTaller($u);
        $r = $this->postJson('/api/inventario', [
            'nombre' => 'Mica', 'categoria' => 'Micas', 'costo' => $costo, 'precio' => $precio, 'precio_locatario' => $mayoreo,
            'minimo' => 1, 'existencia_inicial' => $hay,
        ])->assertCreated();

        return Producto::withoutGlobalScopes()->findOrFail($r->json('producto.id'));
    }

    // ---------------------------------------------------------------- aislamiento entre talleres

    public function test_un_taller_no_ve_ni_abre_los_datos_de_otro(): void
    {
        $telefono = $this->telefonoDe($this->adminA);
        $producto = $this->productoDe($this->adminA);
        $this->comoTaller($this->adminA);
        $cliente = $this->postJson('/api/clientes', ['nombre' => 'Ana', 'telefono' => '5512345678'])->assertCreated();
        $orden = $this->postJson('/api/ordenes', [
            'cliente_id' => $cliente->json('id'),
            'equipo_nuevo' => ['tipo' => 'celular', 'marca' => 'Apple', 'modelo' => 'iPhone 13'],
            'falla_reportada' => 'Pantalla',
        ])->assertCreated();

        $this->comoTaller($this->adminB);
        $this->getJson('/api/telefonos')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/clientes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/ordenes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/inventario')->assertOk()->assertJsonCount(0, 'productos');
        $this->getJson("/api/telefonos/{$telefono->id}")->assertNotFound();
        $this->getJson("/api/clientes/{$cliente->json('id')}")->assertNotFound();
        $this->getJson("/api/ordenes/{$orden->json('id')}")->assertNotFound();
        $this->getJson("/api/inventario/{$producto->id}")->assertNotFound();
        $this->putJson("/api/telefonos/{$telefono->id}", [])->assertNotFound();
        $this->deleteJson("/api/telefonos/{$telefono->id}")->assertNotFound();
        $this->postJson("/api/telefonos/{$telefono->id}/accion", ['accion' => 'listo'])->assertNotFound();
    }

    public function test_no_se_puede_apuntar_a_datos_de_otro_taller_al_crear(): void
    {
        $this->comoTaller($this->adminA);
        $cliente = $this->postJson('/api/clientes', ['nombre' => 'Ana', 'telefono' => '5512345678'])->assertCreated();
        $sucursalA = Sucursal::withoutGlobalScopes()->where('taller_id', $this->tallerA->id)->first();

        $this->comoTaller($this->adminB);
        $this->postJson('/api/equipos', ['cliente_id' => $cliente->json('id'), 'tipo' => 'celular', 'marca' => 'X', 'modelo' => 'Y'])->assertUnprocessable();
        $this->postJson('/api/ordenes', [
            'cliente_id' => $cliente->json('id'),
            'equipo_nuevo' => ['tipo' => 'celular', 'marca' => 'X', 'modelo' => 'Y'],
            'falla_reportada' => 'Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
        $this->postJson('/api/usuarios', [
            'name' => 'Espía', 'email' => 'espia@test.mx', 'password' => 'password123', 'sucursal_id' => $sucursalA->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sucursal_id');
    }

    public function test_el_administrador_solo_ve_y_modifica_usuarios_de_su_taller(): void
    {
        $this->comoTaller($this->adminB);
        $this->getJson('/api/usuarios')->assertOk()->assertJsonPath('total', 1);
        $this->putJson("/api/usuarios/{$this->adminA->id}", ['name' => 'Hackeado', 'email' => 'x@test.mx'])->assertNotFound();
        $this->deleteJson("/api/usuarios/{$this->adminA->id}")->assertNotFound();
        $this->assertNotSame('Hackeado', $this->adminA->fresh()->name);
    }

    public function test_cambiar_de_sucursal_separa_los_datos_y_no_se_puede_elegir_una_ajena(): void
    {
        $this->comoTaller($this->adminA);
        $matriz = Sucursal::where('taller_id', $this->tallerA->id)->first();
        $otra = $this->postJson('/api/sucursales', ['nombre' => 'Plaza'])->assertCreated()->json('sucursal.id');
        $ajena = Sucursal::withoutGlobalScopes()->where('taller_id', $this->tallerB->id)->value('id');

        $this->telefonoDe($this->adminA);
        request()->attributes->remove('sucursal_id');
        $this->withHeader('X-Sucursal', (string) $otra)->getJson('/api/telefonos')->assertJsonPath('total', 0);
        request()->attributes->remove('sucursal_id');
        $this->withHeader('X-Sucursal', (string) $matriz->id)->getJson('/api/telefonos')->assertJsonPath('total', 1);
        // una sucursal de otro taller se ignora: se queda en la primera propia
        request()->attributes->remove('sucursal_id');
        $this->withHeader('X-Sucursal', (string) $ajena)->getJson('/api/telefonos')->assertJsonPath('total', 1);
    }

    public function test_la_suscripcion_vencida_bloquea_todo_menos_la_pantalla_de_suscripcion(): void
    {
        $this->tallerA->update(['estado' => Taller::PRUEBA, 'prueba_hasta' => today()->subDay()]);
        $this->comoTaller($this->adminA->fresh());
        $this->getJson('/api/telefonos')->assertStatus(402);
        $this->getJson('/api/suscripcion')->assertOk()->assertJsonPath('vigente', false);
        $this->getJson('/api/me')->assertOk();

        // el dueño del sistema no se bloquea
        $this->adminA->assignRole('Superadmin');
        $this->comoTaller($this->adminA->fresh());
        $this->getJson('/api/telefonos')->assertOk();
    }

    // ---------------------------------------------------------------- permisos

    public function test_permisos_por_rol(): void
    {
        $telefono = $this->telefonoDe($this->adminA);
        $tecnico = $this->usuario($this->tallerA, 'Tecnico');

        $this->comoTaller($tecnico);
        $this->getJson('/api/telefonos')->assertOk();
        $this->postJson("/api/telefonos/{$telefono->id}/accion", ['accion' => 'listo'])->assertOk();
        $this->postJson("/api/telefonos/{$telefono->id}/accion", ['accion' => 'abono', 'monto' => 10])->assertForbidden();
        $this->deleteJson("/api/telefonos/{$telefono->id}")->assertForbidden();
        $this->getJson('/api/cortes')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/respaldo')->assertForbidden();
        $this->getJson('/api/admin/talleres')->assertForbidden();
    }

    public function test_solo_el_dueno_del_sistema_modifica_roles_y_otorga_superadmin(): void
    {
        $this->comoTaller($this->adminA);
        $this->getJson('/api/admin/talleres')->assertForbidden();
        $this->postJson('/api/roles', ['name' => 'Nuevo'])->assertForbidden();
        $this->postJson('/api/usuarios', [
            'name' => 'Escalado', 'email' => 'esc@test.mx', 'password' => 'password123', 'roles' => ['Superadmin'],
        ])->assertUnprocessable();
        $nombres = collect($this->getJson('/api/roles')->assertOk()->json())->pluck('name');
        $this->assertNotContains('Superadmin', $nombres);

        // un administrador tampoco puede cambiarle la contraseña al dueño del sistema
        $dueno = $this->usuario($this->tallerA, 'Superadmin');
        $this->putJson("/api/usuarios/{$dueno->id}", ['name' => 'X', 'email' => 'dueno@test.mx', 'password' => 'nuevaclave123'])->assertForbidden();

        $this->comoTaller($dueno);
        $this->getJson('/api/admin/talleres')->assertOk()->assertJsonPath('resumen.total', 2);
        $this->postJson('/api/roles', ['name' => 'Nuevo'])->assertCreated();
    }

    // ---------------------------------------------------------------- teléfonos y caja

    public function test_cobros_de_un_telefono_entran_a_la_caja(): void
    {
        $t = $this->telefonoDe($this->adminA);

        $this->assertSame(1, Pago::where('telefono_id', $t->id)->count());
        $this->assertSame('Anticipo', Pago::where('telefono_id', $t->id)->value('concepto'));
        $this->assertEquals(100.0, (float) Pago::where('telefono_id', $t->id)->sum('monto'));

        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'abono', 'monto' => 150])->assertOk();
        $this->assertEquals(250.0, (float) $t->fresh()->pagado);

        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'liquidar'])->assertOk();
        $this->assertEquals(500.0, (float) $t->fresh()->pagado);
        $this->assertEquals(0.0, $t->fresh()->saldo);
        $this->assertEquals(500.0, (float) Pago::where('telefono_id', $t->id)->sum('monto'));

        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'liquidar'])->assertUnprocessable();
    }

    public function test_si_no_quedo_no_se_cobra_y_el_boton_regresa_a_en_taller(): void
    {
        $t = $this->telefonoDe($this->adminA);

        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'noquedo'])->assertOk();
        $this->assertEquals(0.0, $t->fresh()->saldo);
        $this->assertSame('NO SE COBRA', $t->fresh()->estado_pago);
        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'liquidar'])->assertUnprocessable();

        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'noquedo'])->assertOk();
        $this->assertSame(Telefono::EN_TALLER, $t->fresh()->resultado);
    }

    public function test_marcar_listo_ofrece_aviso_por_whatsapp_con_saldo(): void
    {
        $t = $this->telefonoDe($this->adminA, ['whatsapp' => '5512345678']);

        $r = $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'listo'])->assertOk();
        $url = $r->json('aviso.url');
        $this->assertStringStartsWith('https://wa.me/525512345678?text=', $url);
        $this->assertStringContainsString('Saldo pendiente: $400.00', rawurldecode($url));
    }

    public function test_al_eliminar_se_decide_que_pasa_con_los_cobros(): void
    {
        $conservar = $this->telefonoDe($this->adminA);
        $this->deleteJson("/api/telefonos/{$conservar->id}", ['cobros' => 'conservar'])->assertNoContent();
        $this->assertEquals(100.0, (float) Pago::sum('monto'));

        $quitar = $this->telefonoDe($this->adminA, ['modelo' => 'A15']);
        $this->deleteJson("/api/telefonos/{$quitar->id}", ['cobros' => 'quitar'])->assertNoContent();
        $this->assertEquals(100.0, (float) Pago::sum('monto'));
    }

    public function test_corte_de_caja_solo_cuenta_efectivo_y_toma_el_fondo_anterior(): void
    {
        $this->telefonoDe($this->adminA);   // 100 en efectivo
        $p = $this->productoDe($this->adminA);
        $this->comoTaller($this->adminA);
        $this->postJson('/api/ventas', [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 1, 'precio' => 100]], 'metodo' => 'TARJETA',
        ])->assertCreated();
        $this->postJson('/api/salidas', ['concepto' => 'Comida', 'monto' => 30, 'fecha' => now()->format('Y-m-d\TH:i')])->assertCreated();

        $hoy = today()->toDateString();
        $r = $this->getJson('/api/cortes?fecha='.$hoy)->assertOk();
        $r->assertJsonPath('entradas', 100);
        $r->assertJsonPath('total_salidas', 30);
        $this->assertEquals(100.0, $r->json('otros_metodos.TARJETA'));

        $this->postJson('/api/cortes', ['fecha' => $hoy, 'fondo' => 200, 'contado' => 265])->assertOk();
        $corte = Corte::first();
        $this->assertEquals(270.0, (float) $corte->esperado);   // 200 + 100 - 30
        $this->assertEquals(-5.0, (float) $corte->diferencia);

        // volver a guardar reemplaza, no duplica
        $this->postJson('/api/cortes', ['fecha' => $hoy, 'fondo' => 200, 'contado' => 270])->assertOk();
        $this->assertSame(1, Corte::count());

        $mañana = today()->addDay()->toDateString();
        $this->assertEquals(200.0, $this->getJson('/api/cortes?fecha='.$mañana)->json('fondo'));
    }

    public function test_saldo_a_favor_deposito_aplicar_devolver_y_eliminar(): void
    {
        $t = $this->telefonoDe($this->adminA, ['pagado' => 0]);   // debe 500
        $loc = Locatario::first();

        $this->postJson('/api/saldos/deposito', ['locatario' => 'don pepe', 'monto' => 300])->assertCreated();
        $this->assertSame(1, Locatario::count(), 'Busca sin importar mayúsculas: no duplica al locatario');
        $this->assertEquals(300.0, (float) Pago::sum('monto'), 'El depósito entra a la caja');
        $this->assertEquals(300.0, $loc->saldoAFavor());

        $this->postJson("/api/saldos/{$loc->id}/aplicar")->assertOk();
        $this->assertEquals(300.0, (float) $t->fresh()->pagado);
        $this->assertEquals(0.0, $loc->saldoAFavor());
        $this->assertEquals(300.0, (float) Pago::sum('monto'), 'Aplicar no es dinero nuevo en caja');

        // no se puede borrar un depósito que ya se usó
        $deposito = Credito::where('tipo', Credito::DEPOSITO)->first();
        $this->deleteJson("/api/saldos/movimiento/{$deposito->id}")->assertUnprocessable();

        $aplicado = Credito::where('tipo', Credito::APLICADO)->first();
        $this->deleteJson("/api/saldos/movimiento/{$aplicado->id}")->assertOk();
        $this->assertEquals(0.0, (float) $t->fresh()->pagado);
        $this->assertEquals(300.0, $loc->saldoAFavor());

        $this->postJson("/api/saldos/{$loc->id}/devolver", ['monto' => 400])->assertUnprocessable();
        $this->postJson("/api/saldos/{$loc->id}/devolver", ['monto' => 100])->assertOk();
        $this->assertEquals(200.0, $loc->saldoAFavor());
        $this->assertEquals(100.0, (float) Salida::sum('monto'));
    }

    public function test_resumen_por_locatario_y_estado_de_cuenta(): void
    {
        $t = $this->telefonoDe($this->adminA);
        $this->postJson("/api/telefonos/{$t->id}/accion", ['accion' => 'entregado'])->assertOk();

        $fila = $this->getJson('/api/resumen')->assertOk()->json('0');
        $this->assertEquals(400.0, $fila['debe']);
        $this->assertSame(1, $fila['entregados_deben']);
        $this->assertStringContainsString('Total a pagar: $400.00', rawurldecode($fila['cuenta_url']));
    }

    // ---------------------------------------------------------------- inventario y ventas

    public function test_venta_descuenta_existencia_y_cobra_en_caja(): void
    {
        $p = $this->productoDe($this->adminA);

        $r = $this->postJson('/api/ventas', [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 3, 'precio' => 100]], 'metodo' => 'EFECTIVO', 'descuento' => 50, 'recibido' => 300,
        ])->assertCreated();
        $venta = Venta::findOrFail($r->json('venta.id'));

        $this->assertEquals(250.0, (float) $venta->total);
        $this->assertEquals(50.0, $venta->cambio);
        $this->assertEquals(7, $p->existencia()->first()->cantidad);
        $this->assertEquals(250.0, (float) Pago::where('venta_id', $venta->id)->sum('monto'));
        $this->assertEquals(70.0, $venta->ganancia);   // 250 - 3 x 60

        $this->postJson('/api/ventas', [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 99, 'precio' => 100]], 'metodo' => 'EFECTIVO',
        ])->assertUnprocessable();
        $this->assertEquals(7, $p->existencia()->first()->cantidad);

        $this->postJson('/api/ventas', [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 1, 'precio' => 100]], 'metodo' => 'EFECTIVO', 'recibido' => 50,
        ])->assertUnprocessable();
    }

    public function test_solo_se_vende_a_credito_a_locatarios_y_los_abonos_la_liquidan(): void
    {
        $p = $this->productoDe($this->adminA);
        $linea = [['producto_id' => $p->id, 'cantidad' => 2, 'precio' => 80]];

        $this->postJson('/api/ventas', ['lineas' => $linea, 'metodo' => 'CRÉDITO'])->assertUnprocessable();

        Locatario::create(['nombre' => 'Don Pepe']);
        $r = $this->postJson('/api/ventas', ['lineas' => $linea, 'metodo' => 'CRÉDITO', 'tipo' => 'LOCATARIO', 'locatario' => 'Don Pepe'])->assertCreated();
        $venta = Venta::findOrFail($r->json('venta.id'));

        $this->assertSame(Venta::PENDIENTE, $venta->estatus);
        $this->assertEquals(0.0, (float) Pago::where('venta_id', $venta->id)->sum('monto'), 'A crédito no entra dinero');
        $this->assertEquals(160.0, Locatario::first()->deuda());

        $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 200, 'metodo' => 'EFECTIVO'])->assertUnprocessable();
        $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 160, 'metodo' => 'EFECTIVO'])->assertOk();
        $this->assertSame(Venta::PAGADA, $venta->fresh()->estatus);
    }

    public function test_devolucion_reparte_descuento_regresa_piezas_y_dinero(): void
    {
        $p = $this->productoDe($this->adminA);
        $r = $this->postJson('/api/ventas', [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 2, 'precio' => 100]], 'metodo' => 'EFECTIVO', 'descuento' => 20,
        ])->assertCreated();
        $venta = Venta::findOrFail($r->json('venta.id'));
        $detalle = $venta->detalles()->first();

        $this->postJson("/api/ventas/{$venta->id}/devolver", ['piezas' => [$detalle->id => 1]])->assertOk();
        $venta->refresh();
        $this->assertEquals(90.0, (float) $venta->devuelto);    // 100 x (180/200)
        $this->assertEquals(90.0, $venta->neto);
        $this->assertEquals(9, $p->existencia()->first()->cantidad);
        $this->assertEquals(90.0, (float) Pago::where('venta_id', $venta->id)->sum('monto'));

        $this->postJson("/api/ventas/{$venta->id}/devolver", ['piezas' => [$detalle->id => 5]])->assertUnprocessable();
    }

    public function test_cancelar_venta_regresa_piezas_y_solo_lo_hace_el_administrador(): void
    {
        $p = $this->productoDe($this->adminA);
        $r = $this->postJson('/api/ventas', ['lineas' => [['producto_id' => $p->id, 'cantidad' => 2, 'precio' => 100]], 'metodo' => 'EFECTIVO'])->assertCreated();
        $venta = Venta::findOrFail($r->json('venta.id'));

        $this->comoTaller($this->usuario($this->tallerA, 'Empleado'));
        $this->postJson("/api/ventas/{$venta->id}/cancelar")->assertForbidden();

        $this->comoTaller($this->adminA);
        $this->postJson("/api/ventas/{$venta->id}/cancelar")->assertOk();
        $this->assertSame(Venta::CANCELADA, $venta->fresh()->estatus);
        $this->assertEquals(10, $p->existencia()->first()->cantidad);
        $this->assertEquals(0.0, (float) Pago::where('venta_id', $venta->id)->sum('monto'));
        $this->postJson("/api/ventas/{$venta->id}/cancelar")->assertUnprocessable();
    }

    public function test_precio_de_locatario_y_traspaso_entre_sucursales(): void
    {
        $p = $this->productoDe($this->adminA);
        $this->assertEquals(80.0, $p->precioPara(Venta::LOCATARIO));
        $this->assertEquals(100.0, $p->precioPara(Venta::CLIENTE));

        $otra = $this->postJson('/api/sucursales', ['nombre' => 'Plaza'])->assertCreated()->json('sucursal.id');
        $this->postJson("/api/inventario/{$p->id}/movimiento", ['tipo' => 'traspaso', 'piezas' => 4, 'destino' => $otra])->assertOk();
        $this->assertEquals(6, DB::table('existencias')->where('producto_id', $p->id)->where('sucursal_id', '!=', $otra)->value('cantidad'));
        $this->assertEquals(4, DB::table('existencias')->where('producto_id', $p->id)->where('sucursal_id', $otra)->value('cantidad'));

        $this->postJson("/api/inventario/{$p->id}/movimiento", ['tipo' => 'traspaso', 'piezas' => 99, 'destino' => $otra])->assertUnprocessable();
    }

    public function test_entrada_pagada_de_caja_registra_la_salida(): void
    {
        $p = $this->productoDe($this->adminA);
        $this->postJson("/api/inventario/{$p->id}/movimiento", ['tipo' => 'entrada', 'piezas' => 5, 'importe' => 300, 'de_caja' => true])->assertOk();
        $this->assertEquals(15, $p->existencia()->first()->cantidad);
        $this->assertEquals(300.0, (float) Salida::sum('monto'));
    }

    // ---------------------------------------------------------------- órdenes de clientes finales

    private function ordenNueva(array $extra = []): array
    {
        $this->comoTaller($this->adminA);

        return $this->postJson('/api/ordenes', $extra + [
            'cliente_nuevo' => ['nombre' => 'Carlos', 'apellido' => 'Ramírez', 'telefono' => '55 1234-5678'],
            'equipo_nuevo' => ['tipo' => 'celular', 'marca' => 'Apple', 'modelo' => 'iPhone 13', 'imei' => '353912110000001'],
            'falla_reportada' => 'Pantalla rota',
            'costo_estimado' => 1000,
            'anticipo' => 300,
            'metodo_pago' => 'tarjeta',
            'bloqueo_tipo' => 'pin',
            'bloqueo_valor' => '1234',
            'revision' => ['bateria' => 'si', 'golpes' => 'no', 'inventado' => 'si'],
        ])->assertCreated()->json();
    }

    public function test_orden_nueva_asigna_folio_cobra_anticipo_y_cifra_el_pin(): void
    {
        $o = $this->ordenNueva();
        $this->assertSame(1, $o['folio']);
        $this->assertSame(32, strlen($o['token']));
        $this->assertEquals(700.0, $o['saldo']);
        $this->assertSame(['bateria' => 'si', 'golpes' => 'no'], $o['revision'], 'Solo se guardan los puntos válidos');

        $pago = Pago::where('orden_id', $o['id'])->first();
        $this->assertEquals(300.0, (float) $pago->monto);
        $this->assertSame('TARJETA', $pago->metodo);
        $this->assertSame('Anticipo', $pago->concepto);

        // en la base va cifrado; la ficha lo muestra y el listado no
        $crudo = DB::table('ordenes_reparacion')->where('id', $o['id'])->value('bloqueo_valor');
        $this->assertNotSame('1234', $crudo);
        $this->assertStringNotContainsString('1234', $crudo);
        $this->assertSame('1234', $o['bloqueo_valor']);
        $this->assertArrayNotHasKey('bloqueo_valor', $this->getJson('/api/ordenes')->json('data.0'));

        // el segundo folio es consecutivo y por taller
        $this->assertSame(2, $this->ordenNueva(['bloqueo_tipo' => 'ninguno', 'bloqueo_valor' => null])['folio']);
        $this->assertSame(1, Cliente::count(), 'Mismo teléfono: reutiliza al cliente');
        $this->assertSame('5512345678', Cliente::first()->telefono);
    }

    public function test_cambio_de_estado_abono_y_no_quedo(): void
    {
        $o = $this->ordenNueva();
        $id = $o['id'];

        $r = $this->postJson("/api/ordenes/{$id}/accion", ['accion' => 'estatus', 'estado' => 'reparado'])->assertOk();
        $this->assertStringStartsWith('https://wa.me/525512345678', $r->json('aviso.url'));
        $this->assertStringContainsString('/orden/'.$o['token'], rawurldecode($r->json('aviso.url')));

        $this->postJson("/api/ordenes/{$id}/accion", ['accion' => 'abono', 'monto' => 200])->assertOk();
        $this->assertEquals(500.0, (float) OrdenReparacion::find($id)->pagado);
        $this->postJson("/api/ordenes/{$id}/accion", ['accion' => 'liquidar'])->assertOk();
        $this->assertEquals(1000.0, (float) OrdenReparacion::find($id)->pagado);
        $this->postJson("/api/ordenes/{$id}/accion", ['accion' => 'liquidar'])->assertUnprocessable();

        $this->postJson("/api/ordenes/{$id}/accion", ['accion' => 'estatus', 'estado' => 'entregado'])->assertOk();
        $this->assertNotNull(OrdenReparacion::find($id)->fecha_entrega_real);

        $o2 = $this->ordenNueva(['bloqueo_tipo' => 'ninguno', 'bloqueo_valor' => null]);
        $this->postJson("/api/ordenes/{$o2['id']}/accion", ['accion' => 'estatus', 'estado' => 'no_quedo'])->assertOk();
        $this->assertEquals(0.0, OrdenReparacion::find($o2['id'])->saldo, 'Si no quedó, no se cobra');
    }

    public function test_editar_no_cambia_lo_pagado_sin_pasar_por_la_caja(): void
    {
        $o = $this->ordenNueva();
        $this->putJson("/api/ordenes/{$o['id']}", [
            'estado' => 'diagnostico', 'falla_reportada' => 'Otra', 'anticipo' => 999, 'costo_final' => 1200, 'bloqueo_tipo' => 'pin', 'bloqueo_valor' => '1234',
        ])->assertOk();
        $orden = OrdenReparacion::find($o['id']);
        $this->assertEquals(300.0, (float) $orden->pagado);
        $this->assertEquals(900.0, $orden->saldo);
    }

    public function test_consulta_publica_respeta_la_visibilidad_y_nunca_expone_secretos(): void
    {
        $o = $this->ordenNueva(['notas' => 'NOTA-SECRETA', 'notas_cliente' => 'Lleva mica', 'visibilidad' => ['motivo', 'diagnostico']]);
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $this->flushHeaders();
        $sinDigitos = $this->getJson("/api/consulta/{$o['numero_orden']}")->assertOk();
        $this->assertFalse($sinDigitos->json('verificado'));
        $this->assertNull($sinDigitos->json('motivo'));

        $mal = $this->getJson("/api/consulta/{$o['numero_orden']}?telefono=0000")->assertOk();
        $this->assertFalse($mal->json('verificado'));

        $bien = $this->getJson("/api/consulta/{$o['numero_orden']}?telefono=5678")->assertOk();
        $this->assertTrue($bien->json('verificado'));
        $this->assertSame('Pantalla rota', $bien->json('motivo'));
        $this->assertSame('Lleva mica', $bien->json('notas_cliente'));
        $this->assertNull($bien->json('costos'), 'Costos no está permitido en esta orden');

        $enlace = $this->getJson("/api/publico/orden/{$o['token']}")->assertOk();
        $this->assertTrue($enlace->json('verificado'));
        $this->assertSame('Pantalla rota', $enlace->json('motivo'));
        foreach ([$bien, $enlace] as $r) {
            $json = $r->getContent();
            $this->assertStringNotContainsString('NOTA-SECRETA', $json);
            $this->assertStringNotContainsString('1234', $json);
            $this->assertStringNotContainsString('bloqueo', $json);
        }

        $this->getJson('/api/publico/orden/token-inventado')->assertNotFound();
    }

    public function test_pdf_de_orden_y_venta_con_enlace_publico(): void
    {
        $o = $this->ordenNueva();
        $p = $this->productoDe($this->adminA);
        $v = Venta::find($this->postJson('/api/ventas', ['lineas' => [['producto_id' => $p->id, 'cantidad' => 1, 'precio' => 100]], 'metodo' => 'EFECTIVO'])->json('venta.id'));

        $this->app['auth']->forgetGuards();
        foreach (["/api/orden/{$o['token']}/pdf", "/api/venta/{$v->token}/pdf"] as $url) {
            $r = $this->get($url)->assertOk();
            $this->assertStringContainsString('application/pdf', $r->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF', $r->getContent());
        }
        $this->get('/api/orden/inventado/pdf')->assertNotFound();
    }

    public function test_registro_crea_taller_con_prueba_y_administrador(): void
    {
        $this->app['auth']->forgetGuards();
        $r = $this->postJson('/api/registro', [
            'taller' => 'Mi Taller', 'name' => 'Lupe', 'email' => 'lupe@test.mx', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated();
        $u = User::where('email', 'lupe@test.mx')->first();
        $this->assertTrue($u->hasRole('Administrador'));
        $this->assertSame('prueba', $u->taller->estado);
        $this->assertSame('Matriz', Sucursal::withoutGlobalScopes()->where('taller_id', $u->taller_id)->value('nombre'));
        $this->assertTrue($r->json('user.taller.vigente'));
    }

    public function test_el_panel_solo_entrega_las_graficas_que_el_usuario_puede_ver(): void
    {
        $this->telefonoDe($this->adminA);
        $tecnico = $this->usuario($this->tallerA, 'Tecnico');

        // sin permisos: el periodo, y ninguna gráfica
        $this->comoTaller($tecnico);
        $r = $this->getJson('/api/panel')->assertOk();
        foreach (['ingresos', 'ventas', 'reparaciones', 'telefonos', 'cobranza'] as $grafica) {
            $r->assertJsonMissingPath($grafica);
        }

        // con una gráfica asignada: esa y nada más
        $tecnico->givePermissionTo('panel.telefonos');
        $this->comoTaller($tecnico);
        $this->getJson('/api/panel')->assertOk()
            ->assertJsonPath('telefonos.en_taller', 1)
            ->assertJsonMissingPath('ingresos')
            ->assertJsonMissingPath('cobranza');

        // el administrador las ve todas por su rol
        $this->comoTaller($this->adminA);
        $this->getJson('/api/panel')->assertOk()
            ->assertJsonStructure(['ingresos', 'ventas', 'reparaciones', 'telefonos', 'cobranza']);

        $this->getJson('/api/panel?dias=12')->assertStatus(422);
    }

    public function test_el_panel_suma_bien_y_no_mezcla_talleres(): void
    {
        $hoy = now()->toDateString();
        $this->telefonoDe($this->adminA, ['fecha_recibido' => $hoy, 'total' => 500, 'pagado' => 100]);
        $this->telefonoDe($this->adminB, ['fecha_recibido' => $hoy, 'total' => 9000, 'pagado' => 1000, 'locatario' => 'De otro taller']);

        $this->comoTaller($this->adminA);
        $r = $this->getJson('/api/panel?dias=7')->assertOk();

        $this->assertCount(7, $r->json('fechas'));
        $this->assertSame($hoy, $r->json('fechas.6'));
        $this->assertEquals(100, $r->json('ingresos.total_cobrado'));
        $this->assertEquals(100, $r->json('ingresos.cobrado.6'));
        $this->assertEquals(0, $r->json('ingresos.cobrado.0'));
        $this->assertEquals(1, $r->json('telefonos.recibidos.6'));
        $this->assertEquals(400, $r->json('cobranza.total'));
        $this->assertSame('DON PEPE', mb_strtoupper($r->json('cobranza.locatarios.0.nombre')));
        $this->assertCount(1, $r->json('cobranza.locatarios'));
    }

    public function test_las_ventas_del_panel_no_cuentan_canceladas_ni_otro_taller(): void
    {
        $producto = $this->productoDe($this->adminA);
        $this->comoTaller($this->adminA);
        $vender = fn () => $this->postJson('/api/ventas', [
            'tipo' => 'CLIENTE', 'metodo' => 'EFECTIVO', 'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 100]],
        ])->assertCreated();
        $primera = $vender();
        $vender();
        $this->assertSame(1, Venta::withoutGlobalScopes()->where('id', $primera->json('venta.id'))->update(['estatus' => Venta::CANCELADA]));

        $this->comoTaller($this->adminA);
        $r = $this->getJson('/api/panel')->assertOk();
        $this->assertSame(1, $r->json('ventas.numero'));
        $this->assertEquals(200, $r->json('ventas.total'));
        $this->assertSame(2, $r->json('ventas.productos.0.valor'));

        $this->comoTaller($this->adminB);
        $this->assertSame([], $this->getJson('/api/panel')->json('ventas.productos'));
    }

    public function test_las_graficas_se_eligen_por_usuario(): void
    {
        $this->comoTaller($this->adminA);
        $datos = ['name' => 'Lupe', 'email' => 'lupe@taller.mx', 'password' => 'password123', 'roles' => ['Recepcionista']];

        $id = $this->postJson('/api/usuarios', $datos + ['panel' => ['panel.telefonos', 'panel.ingresos']])->assertCreated()->json('id');
        $lupe = User::findOrFail($id);
        $this->assertTrue($lupe->can('panel.ingresos'));
        $this->assertFalse($lupe->can('panel.ventas'));

        // la lista dice cuáles tiene, para marcarlas al editar
        $fila = collect($this->getJson('/api/usuarios')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertEqualsCanonicalizing(['panel.telefonos', 'panel.ingresos'], $fila['panel']);
        $this->assertArrayNotHasKey('permissions', $fila);

        // quitarle una no toca sus otros permisos
        $datos = ['name' => 'Lupe', 'email' => 'lupe@taller.mx', 'roles' => ['Recepcionista']];
        $this->putJson("/api/usuarios/{$id}", $datos + ['panel' => ['panel.telefonos']])->assertOk();
        $lupe = User::findOrFail($id);
        $this->assertFalse($lupe->can('panel.ingresos'));
        $this->assertTrue($lupe->can('panel.telefonos'));
        $this->assertTrue($lupe->can('clientes.ver'));

        // no mandar la lista no cambia nada
        $this->putJson("/api/usuarios/{$id}", $datos)->assertOk();
        $this->assertTrue(User::findOrFail($id)->can('panel.telefonos'));

        // solo se aceptan gráficas, no otros permisos
        $this->putJson("/api/usuarios/{$id}", $datos + ['panel' => ['usuarios.eliminar']])->assertStatus(422);
        $this->assertFalse(User::findOrFail($id)->can('usuarios.eliminar'));

        // nadie da una gráfica que él mismo no puede ver
        $encargado = $this->usuario($this->tallerA, 'Recepcionista');
        $encargado->givePermissionTo(['usuarios.editar', 'panel.telefonos']);
        $this->comoTaller($encargado);
        $this->putJson("/api/usuarios/{$encargado->id}", ['name' => $encargado->name, 'email' => $encargado->email, 'roles' => ['Recepcionista'], 'panel' => ['panel.telefonos', 'panel.ingresos']])->assertOk();
        $this->assertFalse(User::findOrFail($encargado->id)->can('panel.ingresos'));

        // y otro taller no puede tocarlo
        $this->comoTaller($this->adminB);
        $this->putJson("/api/usuarios/{$id}", $datos + ['panel' => ['panel.ventas']])->assertNotFound();
    }

    public function test_preparar_produccion_bloquea_cuentas_de_prueba_y_cierra_sesiones(): void
    {
        $prueba = User::factory()->create(['taller_id' => $this->tallerA->id, 'email' => 'admin@tallercelulares.test', 'password' => 'password']);
        $real = User::factory()->create(['taller_id' => $this->tallerA->id, 'email' => 'dueno@taller.mx', 'password' => 'password']);
        $real->createToken('otra-pc');

        $this->artisan('app:preparar-produccion')->assertSuccessful();

        $this->postJson('/api/login', ['email' => $prueba->email, 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => $real->email, 'password' => 'password'])->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());   // solo el login recién hecho
    }

    public function test_el_respaldo_del_taller_solo_lleva_sus_datos_y_sin_contrasenas(): void
    {
        $this->telefonoDe($this->adminA);
        $this->telefonoDe($this->adminB, ['modelo' => 'DE-OTRO-TALLER']);

        $ruta = \App\Support\Respaldo::crear($this->tallerA->id);
        $contenido = gzdecode(file_get_contents($ruta));
        unlink($ruta);

        $datos = json_decode($contenido, true);
        $this->assertCount(1, $datos['tablas']['telefonos']);
        $this->assertStringNotContainsString('DE-OTRO-TALLER', $contenido);
        $this->assertArrayNotHasKey('password', $datos['tablas']['users'][0]);
    }
}

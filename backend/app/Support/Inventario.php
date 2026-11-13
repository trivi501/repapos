<?php

namespace App\Support;

use App\Models\Credito;
use App\Models\Existencia;
use App\Models\Locatario;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Movimientos de inventario. Cada uno actualiza la existencia de la sucursal y queda en el kárdex.
 * - Entrada (compra): suma piezas; si se pagó con dinero de la caja, registra la salida de dinero.
 * - Venta de mostrador: resta piezas y registra el cobro en la caja.
 * - Ajuste: deja la existencia en lo que se contó.
 * - Traspaso: resta en esta sucursal y suma en la otra.
 */
class Inventario
{
    /** Existencia (bloqueada para escribir) del producto en una sucursal; la crea en 0 si no existe. */
    private static function existencia(Producto $p, int $sucursalId): Existencia
    {
        $e = Existencia::withoutGlobalScopes()->where('sucursal_id', $sucursalId)->where('producto_id', $p->id)->lockForUpdate()->first();
        if (! $e) {
            $e = new Existencia(['producto_id' => $p->id, 'cantidad' => 0]);
            $e->taller_id = $p->taller_id;
            $e->sucursal_id = $sucursalId;
            $e->save();
        }
        return $e;
    }

    private static function registrar(Producto $p, int $sucursalId, string $tipo, int $cantidad, array $extra = []): Movimiento
    {
        $e = static::existencia($p, $sucursalId);
        $e->cantidad += $cantidad;
        $e->save();
        $m = new Movimiento($extra + ['producto_id' => $p->id, 'tipo' => $tipo, 'cantidad' => $cantidad,
            'existencia' => $e->cantidad, 'user_id' => auth()->id()]);
        $m->taller_id = $p->taller_id;
        $m->sucursal_id = $sucursalId;
        $m->save();
        return $m;
    }

    private static function validarQueAlcance(Producto $p, int $sucursalId, int $piezas): void
    {
        $hay = static::existencia($p, $sucursalId)->cantidad;
        if ($piezas > $hay) {
            throw new RuntimeException("Solo hay {$hay} pieza(s) de {$p->nombre} en esta sucursal.");
        }
    }

    public static function entrada(Producto $p, int $piezas, float $costoTotal, bool $pagadoDeCaja, string $nota = ''): Movimiento
    {
        return DB::transaction(function () use ($p, $piezas, $costoTotal, $pagadoDeCaja, $nota) {
            $salidaId = null;
            if ($pagadoDeCaja && $costoTotal > 0) {
                $salidaId = Salida::create([
                    'fecha' => now(), 'concepto' => "Compra: {$piezas} x {$p->nombre}", 'categoria' => 'Refacciones',
                    'monto' => $costoTotal, 'notas' => $nota, 'user_id' => auth()->id(),
                ])->id;
            }
            return static::registrar($p, Sucursal::actualId(), Movimiento::ENTRADA, $piezas,
                ['importe' => $costoTotal, 'nota' => $nota, 'salida_id' => $salidaId]);
        });
    }

    /** Venta rápida de un solo producto (desde el menú del inventario): es una venta normal con un renglón. */
    public static function venta(Producto $p, int $piezas, float $total, string $nota = ''): Venta
    {
        return static::vender([['producto' => $p, 'cantidad' => $piezas, 'precio' => $piezas > 0 ? $total / $piezas : 0]],
            0, Venta::EFECTIVO, null);
    }

    /**
     * Venta de mostrador: descuenta las piezas de la sucursal, registra el cobro en la caja (con su método de pago)
     * y deja la venta con folio para el ticket.
     *
     * @param  array<int, array{producto: Producto, cantidad: int, precio: float}>  $lineas
     */
    public static function vender(array $lineas, float $descuento, string $metodo, ?float $recibido, string $clienteNombre = '', string $clienteTelefono = '', ?Locatario $locatario = null): Venta
    {
        if (! $lineas) {
            throw new RuntimeException('Agrega al menos un producto.');
        }
        // venta a locatario: queda a su nombre y el ticket va a su WhatsApp
        if ($locatario) {
            $clienteNombre = $locatario->nombre;
            $clienteTelefono = $clienteTelefono !== '' ? $clienteTelefono : (string) $locatario->whatsapp;
        }
        return DB::transaction(function () use ($lineas, $descuento, $metodo, $recibido, $clienteNombre, $clienteTelefono, $locatario) {
            $sucursal = Sucursal::actualId();

            // si el mismo producto viene en varios renglones, se valida la suma
            $piezas = [];
            foreach ($lineas as $l) {
                $piezas[$l['producto']->id] = ($piezas[$l['producto']->id] ?? 0) + $l['cantidad'];
            }
            foreach ($lineas as $l) {
                static::validarQueAlcance($l['producto'], $sucursal, $piezas[$l['producto']->id]);
            }

            $subtotal = round(array_sum(array_map(fn ($l) => $l['cantidad'] * $l['precio'], $lineas)), 2);
            $descuento = round(min(max(0, $descuento), $subtotal), 2);
            $total = round($subtotal - $descuento, 2);
            $credito = $metodo === Venta::CREDITO;
            if ($credito && ! $locatario) {
                throw new RuntimeException('Solo se vende a crédito a locatarios.');
            }
            if ($metodo === Venta::EFECTIVO && $recibido !== null && $recibido < $total) {
                throw new RuntimeException('El efectivo recibido (' . dinero($recibido) . ') no alcanza para el total (' . dinero($total) . ').');
            }

            $venta = Venta::create([
                'tipo' => $locatario ? Venta::LOCATARIO : Venta::CLIENTE, 'locatario_id' => $locatario?->id,
                'cliente_nombre' => trim($clienteNombre), 'cliente_telefono' => preg_replace('/\D/', '', $clienteTelefono),
                'subtotal' => $subtotal, 'descuento' => $descuento, 'total' => $total, 'metodo' => $metodo,
                // a crédito no entra dinero: queda debiendo hasta que abone
                'pagado' => $credito ? 0 : $total,
                'estatus' => $credito && $total > 0 ? Venta::PENDIENTE : Venta::PAGADA,
                'recibido' => $metodo === Venta::EFECTIVO ? $recibido : null, 'user_id' => auth()->id(),
            ]);
            $pago = null;
            if (! $credito && $total > 0) {
                $pago = static::movimientoCaja($venta, $total, $locatario ? 'Venta a locatario' : 'Venta', $metodo);
                $venta->update(['pago_id' => $pago->id]);
            }
            foreach ($lineas as $l) {
                $p = $l['producto'];
                $importe = round($l['cantidad'] * $l['precio'], 2);
                $venta->detalles()->create([
                    'producto_id' => $p->id, 'nombre' => $p->nombre, 'cantidad' => $l['cantidad'],
                    'precio' => round($l['precio'], 2), 'costo' => $p->costo, 'importe' => $importe,
                ]);
                static::registrar($p, $sucursal, Movimiento::VENTA, -$l['cantidad'],
                    ['importe' => $importe, 'nota' => "Venta {$venta->folio}", 'pago_id' => $pago?->id, 'venta_id' => $venta->id]);
            }
            return $venta;
        });
    }

    /** Entrada (+) o salida (-) de dinero de la caja ligada a una venta. */
    private static function movimientoCaja(Venta $venta, float $monto, string $concepto, string $metodo): Pago
    {
        $pago = new Pago([
            'fecha' => now(), 'venta_id' => $venta->id, 'locatario_id' => $venta->locatario_id,
            'descripcion' => "Venta {$venta->folio}" . ($venta->cliente_nombre ? " - {$venta->cliente_nombre}" : ''),
            'concepto' => $concepto, 'monto' => round($monto, 2),
            'metodo' => in_array($metodo, Venta::METODOS, true) ? $metodo : Venta::EFECTIVO, 'user_id' => auth()->id(),
        ]);
        $pago->taller_id = $venta->taller_id;
        $pago->sucursal_id = $venta->sucursal_id;
        $pago->save();
        return $pago;
    }

    /** Abono a una venta a crédito: entra a la caja de hoy; al completar el pago, la venta queda pagada. */
    public static function abonarVenta(Venta $venta, float $monto, string $metodo): void
    {
        if ($venta->estatus !== Venta::PENDIENTE || $venta->saldo <= 0) {
            throw new RuntimeException("La venta {$venta->folio} no tiene saldo pendiente.");
        }
        if ($monto > $venta->saldo) {
            throw new RuntimeException('Solo debe ' . dinero($venta->saldo) . ' de esta venta.');
        }
        DB::transaction(function () use ($venta, $monto, $metodo) {
            static::movimientoCaja($venta, $monto, 'Abono a venta a crédito', $metodo);
            $venta->pagado = round((float) $venta->pagado + $monto, 2);
            if ($venta->saldo <= 0) {
                $venta->estatus = Venta::PAGADA;
            }
            $venta->save();
        });
    }

    /**
     * Devolución de productos de una venta. Las piezas regresan al inventario y la venta vale menos:
     * si era a crédito baja lo que debe; si ya había pagado de más, se le devuelve en efectivo
     * o (a locatarios) se le deja como saldo a favor.
     *
     * @param  array<int, int>  $piezas  id del renglón => piezas que regresa
     * @return array{valor: float, reembolso: float, destino: string}
     */
    public static function devolver(Venta $venta, array $piezas, string $destino = 'efectivo', string $nota = ''): array
    {
        if ($venta->estatus === Venta::CANCELADA) {
            throw new RuntimeException('Esta venta está cancelada.');
        }
        $piezas = array_filter(array_map('intval', $piezas), fn ($n) => $n > 0);
        if (! $piezas) {
            throw new RuntimeException('Indica cuántas piezas regresa.');
        }
        return DB::transaction(function () use ($venta, $piezas, $destino, $nota) {
            $detalles = $venta->detalles()->whereIn('id', array_keys($piezas))->lockForUpdate()->get()->keyBy('id');
            // el descuento de la venta se reparte proporcionalmente
            $factor = (float) $venta->subtotal > 0 ? (float) $venta->total / (float) $venta->subtotal : 0;
            $valor = 0;
            foreach ($piezas as $id => $n) {
                $d = $detalles->get($id) ?? throw new RuntimeException('Ese producto no es de esta venta.');
                if ($n > $d->cantidad - $d->devuelto) {
                    throw new RuntimeException("De {$d->nombre} solo se pueden devolver " . ($d->cantidad - $d->devuelto) . ' pieza(s).');
                }
                $d->increment('devuelto', $n);
                $valor += $n * (float) $d->precio * $factor;
                $p = $d->producto_id ? Producto::find($d->producto_id) : null;
                if ($p) {
                    static::registrar($p, $venta->sucursal_id, Movimiento::DEVOLUCION, $n,
                        ['nota' => trim("Devolución venta {$venta->folio}. $nota"), 'venta_id' => $venta->id]);
                }
            }
            $valor = round($valor, 2);
            $venta->devuelto = round((float) $venta->devuelto + $valor, 2);

            // si ya pagó más de lo que ahora vale la venta, se le regresa la diferencia
            $reembolso = max(0, round((float) $venta->pagado - $venta->neto, 2));
            $alSaldo = $destino === 'saldo_favor' && $venta->locatario_id;
            if ($reembolso > 0) {
                if ($alSaldo) {
                    Credito::create([
                        'fecha' => now(), 'locatario_id' => $venta->locatario_id, 'tipo' => Credito::DEPOSITO,
                        'concepto' => "Devolución venta {$venta->folio}", 'monto' => $reembolso, 'user_id' => auth()->id(),
                    ]);
                } else {
                    static::movimientoCaja($venta, -$reembolso, 'Devolución de productos', Venta::EFECTIVO);
                }
                $venta->pagado = round((float) $venta->pagado - $reembolso, 2);
            }
            if ($venta->estatus === Venta::PENDIENTE && $venta->saldo <= 0) {
                $venta->estatus = Venta::PAGADA;
            }
            $venta->save();
            return ['valor' => $valor, 'reembolso' => $reembolso, 'destino' => $reembolso > 0 ? ($alSaldo ? 'saldo_favor' : 'efectivo') : 'ninguno'];
        });
    }

    /** Cancela una venta: regresa las piezas que no se habían devuelto y lo que pagó sale de la caja de hoy. */
    public static function cancelar(Venta $venta): void
    {
        if ($venta->estatus === Venta::CANCELADA) {
            throw new RuntimeException("La venta {$venta->folio} ya estaba cancelada.");
        }
        DB::transaction(function () use ($venta) {
            foreach ($venta->detalles as $d) {
                $p = $d->producto_id ? Producto::find($d->producto_id) : null;
                $quedan = $d->cantidad - $d->devuelto;
                if ($p && $quedan > 0) {
                    static::registrar($p, $venta->sucursal_id, Movimiento::CANCELACION, $quedan,
                        ['nota' => "Cancelación venta {$venta->folio}", 'venta_id' => $venta->id]);
                }
            }
            if ((float) $venta->pagado > 0) {
                static::movimientoCaja($venta, -(float) $venta->pagado, 'Cancelación de venta', $venta->metodo);
            }
            $venta->update(['estatus' => Venta::CANCELADA, 'cancelada_at' => now()]);
        });
    }

    /** Deja la existencia en lo que se contó físicamente. */
    public static function ajuste(Producto $p, int $contado, string $nota = ''): ?Movimiento
    {
        return DB::transaction(function () use ($p, $contado, $nota) {
            $dif = $contado - static::existencia($p, Sucursal::actualId())->cantidad;
            return $dif === 0 ? null : static::registrar($p, Sucursal::actualId(), Movimiento::AJUSTE, $dif,
                ['nota' => $nota ?: 'Conteo físico']);
        });
    }

    /** Saca piezas del inventario de la sucursal de la orden para usarlas en una reparación. */
    public static function usarEnReparacion(Producto $p, int $piezas, int $sucursalId, string $nota): Movimiento
    {
        return DB::transaction(function () use ($p, $piezas, $sucursalId, $nota) {
            static::validarQueAlcance($p, $sucursalId, $piezas);

            return static::registrar($p, $sucursalId, Movimiento::REPARACION, -$piezas,
                ['importe' => round($piezas * (float) $p->costo, 2), 'nota' => $nota]);
        });
    }

    /** Regresa al inventario las piezas de una refacción que se quitó de la orden. */
    public static function regresarDeReparacion(Producto $p, int $piezas, int $sucursalId, string $nota): Movimiento
    {
        return DB::transaction(fn () => static::registrar($p, $sucursalId, Movimiento::REPARACION, $piezas, ['nota' => $nota]));
    }

    public static function traspaso(Producto $p, int $piezas, Sucursal $destino, string $nota = ''): void
    {
        DB::transaction(function () use ($p, $piezas, $destino, $nota) {
            $origen = Sucursal::actual();
            if ($destino->id === $origen->id) {
                throw new RuntimeException('Elige una sucursal distinta a esta.');
            }
            static::validarQueAlcance($p, $origen->id, $piezas);
            static::registrar($p, $origen->id, Movimiento::TRASPASO, -$piezas, ['nota' => trim("A {$destino->nombre}. $nota")]);
            static::registrar($p, $destino->id, Movimiento::TRASPASO, $piezas, ['nota' => trim("De {$origen->nombre}. $nota")]);
        });
    }
}

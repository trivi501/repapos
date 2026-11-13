import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged, switchMap } from 'rxjs';
import { OrdenReparacion, ProductoRefaccion, REVISION_ORDEN, RefaccionOrden, estadosPara } from '../../core/models/taller.model';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DineroPipe, Estatus, FechaPipe, PatronVista, dinero } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';

type Ficha = OrdenReparacion & {
  enlaces: { publico: string; pdf_publico: string; whatsapp_ticket: string; whatsapp_estatus: string };
  estado_texto: string;
  total: number;
  saldo: number;
  pagado: string;
  created_at: string;
};

@Component({
  selector: 'app-reparacion-detalle',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe, Estatus, PatronVista],
  templateUrl: './reparacion-detalle.html',
})
export class ReparacionDetalle implements OnInit {
  readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly o = signal<Ficha | null>(null);
  readonly revision = REVISION_ORDEN;
  readonly metodoTexto = metodoTexto;
  /** Se está escribiendo el motivo para rechazar la liberación. */
  readonly rechazando = signal(false);

  monto: number | null = null;
  motivo = '';
  metodo = 'EFECTIVO';
  destinoCobros = '';

  // ---- agregar refacción: del inventario (descuenta la pieza) o comprada fuera
  private readonly destroyRef = inject(DestroyRef);
  private readonly busqueda = new Subject<string>();
  readonly agregandoRefaccion = signal(false);
  readonly productos = signal<ProductoRefaccion[]>([]);
  readonly guardandoRefaccion = signal(false);
  origenRefaccion: 'inventario' | 'fuera' = 'inventario';
  textoProducto = '';
  productoElegido: ProductoRefaccion | null = null;
  refDescripcion = '';
  refCantidad = 1;
  refCosto: number | null = null;

  private get id(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }

  /** Costos de las refacciones, mano de obra, mano externa y ganancia: solo el dueño. */
  get verCostos(): boolean {
    return this.auth.tienePermiso('ordenes.ver_costos');
  }

  ngOnInit(): void {
    this.api.get<Ficha>(`ordenes/${this.id}`).subscribe({
      next: (o) => this.mostrar(o),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
    this.busqueda
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((q) => this.api.get<ProductoRefaccion[]>('ordenes-refacciones/productos', { q })),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({ next: (p) => this.productos.set(p), error: (e: HttpErrorResponse) => this.avisos.fallo(e) });
  }

  abrirRefaccion(): void {
    this.origenRefaccion = 'inventario';
    this.textoProducto = '';
    this.productoElegido = null;
    this.refDescripcion = '';
    this.refCantidad = 1;
    this.refCosto = null;
    this.productos.set([]);
    this.agregandoRefaccion.set(true);
    this.busqueda.next(' '); // trae los primeros productos para elegir sin escribir
  }

  buscarProducto(texto: string): void {
    this.textoProducto = texto;
    this.productoElegido = null;
    this.busqueda.next(texto.trim() || ' ');
  }

  elegirProducto(p: ProductoRefaccion): void {
    this.productoElegido = p;
    this.textoProducto = p.nombre;
    this.productos.set([]);
  }

  /** Listo para guardar: un producto con piezas suficientes, o el nombre de la que se compró fuera. */
  puedeGuardarRefaccion(): boolean {
    if (!(this.refCantidad >= 1) || this.guardandoRefaccion()) return false;
    if (this.origenRefaccion === 'fuera') return this.refDescripcion.trim() !== '';
    return !!this.productoElegido && this.refCantidad <= this.productoElegido.hay;
  }

  guardarRefaccion(): void {
    if (!this.puedeGuardarRefaccion()) return;
    const cuerpo: Record<string, unknown> =
      this.origenRefaccion === 'inventario'
        ? { producto_id: this.productoElegido!.id, cantidad: this.refCantidad }
        : { descripcion: this.refDescripcion.trim(), cantidad: this.refCantidad };
    if (this.verCostos && this.origenRefaccion === 'fuera' && this.refCosto != null) cuerpo['costo_unitario'] = this.refCosto;
    this.guardandoRefaccion.set(true);
    this.api.post<{ message: string; orden: Ficha }>(`ordenes/${this.id}/refacciones`, cuerpo).subscribe({
      next: (r) => {
        this.guardandoRefaccion.set(false);
        this.agregandoRefaccion.set(false);
        this.avisos.ok(r.message);
        this.mostrar(r.orden);
      },
      error: (e: HttpErrorResponse) => {
        this.guardandoRefaccion.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  quitarRefaccion(r: RefaccionOrden): void {
    const aviso = r.del_inventario ? ' La pieza regresa al inventario.' : '';
    if (!confirm(`¿Quitar "${r.descripcion}" de la orden?${aviso}`)) return;
    this.api.delete<{ message: string; orden: Ficha }>(`ordenes/${this.id}/refacciones/${r.id}`).subscribe({
      next: (res) => {
        this.avisos.ok(res.message);
        this.mostrar(res.orden);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  private mostrar(o: Ficha): void {
    this.monto = o.saldo > 0 ? o.saldo : null;
    this.rechazando.set(false);
    this.motivo = '';
    this.o.set(o);
  }

  /** Una liberación solo tiene Recibido, En proceso, Listo y Rechazado. */
  estadosDe(x: Ficha) {
    return estadosPara(x.bloqueos);
  }

  /** Lo que entra por la orden: nada si no quedó, se rechazó o se canceló. */
  ingreso(x: Ficha): number {
    return ['no_quedo', 'cancelado', 'rechazado'].includes(x.estado) ? 0 : x.total;
  }

  nombresBloqueos(x: Ficha): string {
    const nombres: Record<string, string> = { operador: 'Operador', frp: 'FRP', icloud: 'iCloud', mdm: 'MDM', knox: 'Knox' };
    return (x.bloqueos ?? []).map((b) => nombres[b] ?? b).join(', ');
  }

  nombreCliente(x: Ficha): string {
    return `${x.cliente?.nombre ?? ''} ${x.cliente?.apellido ?? ''}`.trim();
  }

  cobrosTotal(x: Ficha): number {
    return (x.pagos ?? []).reduce((s, p) => s + Number(p.monto), 0);
  }

  private accion(cuerpo: Record<string, unknown>): void {
    this.api.post<{ message: string; aviso: AvisoWhatsapp | null; orden: Ficha }>(`ordenes/${this.id}/accion`, cuerpo).subscribe({
      next: (r) => {
        this.avisos.ok(r.message, r.aviso);
        this.mostrar(r.orden);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  abonar(): void {
    if (this.monto && this.monto > 0) this.accion({ accion: 'abono', monto: this.monto, metodo: this.metodo });
  }

  liquidar(x: Ficha): void {
    if (confirm(`¿Liquidar ${dinero(x.saldo)}?`)) this.accion({ accion: 'liquidar', metodo: this.metodo });
  }

  cambiarEstado(x: Ficha, estado: string): void {
    if (estado === 'entregado' && x.saldo > 0 && !confirm(`Aún debe ${dinero(x.saldo)}. ¿Entregar de todos modos?`)) return;
    // rechazar siempre lleva el motivo: primero se escribe
    if (estado === 'rechazado') {
      this.rechazando.set(true);
      return;
    }
    this.accion({ accion: 'estatus', estado });
  }

  confirmarRechazo(): void {
    const motivo = this.motivo.trim();
    if (!motivo) return;
    this.accion({ accion: 'estatus', estado: 'rechazado', motivo });
  }

  eliminar(x: Ficha): void {
    if (!confirm(`¿Eliminar la orden N° ${x.folio}? No se puede deshacer.`)) return;
    this.api.delete(`ordenes/${x.id}`, { cobros: this.destinoCobros }).subscribe({
      next: () => this.router.navigate(['/reparaciones']).then(() => this.avisos.ok(`Orden folio ${x.folio} eliminada.`)),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

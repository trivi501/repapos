import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { OrdenReparacion, REVISION_ORDEN, estadosPara } from '../../core/models/taller.model';
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

  private get id(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }

  ngOnInit(): void {
    this.api.get<Ficha>(`ordenes/${this.id}`).subscribe({
      next: (o) => this.mostrar(o),
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

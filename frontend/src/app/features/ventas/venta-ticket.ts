import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { DineroPipe, FechaPipe } from '../../shared/ui';
import { Venta, metodoTexto } from './ventas';

interface Ticket {
  venta: Venta & { sucursal?: { direccion: string; telefono: string } };
  taller: { nombre: string; logo_url: string | null };
  pie: string;
}

/** Ticket para impresora de 80 mm (también se ve bien en 58 mm y en hoja carta). */
@Component({
  selector: 'app-venta-ticket',
  imports: [RouterLink, DineroPipe, FechaPipe],
  styleUrl: './venta-ticket.scss',
  template: `
    @if (t(); as d) {
      <div class="botones no-imprimir">
        <button class="btn btn-primary" (click)="imprimir()">Imprimir</button>
        <a class="btn btn-secondary" [routerLink]="['/ventas', d.venta.id]">Regresar</a>
      </div>
      <div class="ticket">
        <div class="centro">
          @if (d.taller.logo_url) {
            <img [src]="d.taller.logo_url" alt="" class="logo" />
          }
          <div class="grande">{{ d.taller.nombre }}</div>
          @if (d.venta.sucursal?.direccion) {
            <div>{{ d.venta.sucursal?.direccion }}</div>
          }
          @if (d.venta.sucursal?.telefono) {
            <div>Tel. {{ d.venta.sucursal?.telefono }}</div>
          }
        </div>
        <div class="linea"></div>
        <div>Venta N° {{ folio(d.venta.folio) }}</div>
        <div>{{ d.venta.created_at | fecha: true }} · {{ d.venta.user?.name }}</div>
        @if (d.venta.cliente_nombre) {
          <div>{{ d.venta.tipo === 'LOCATARIO' ? 'Locatario' : 'Cliente' }}: {{ d.venta.cliente_nombre }}</div>
        }
        @if (d.venta.estatus === 'CANCELADA') {
          <div class="grande centro">*** CANCELADA ***</div>
        }
        <div class="linea"></div>
        <table>
          @for (l of d.venta.detalles; track l.id) {
            <tr><td colspan="2">{{ l.nombre }}</td></tr>
            <tr><td>&nbsp; {{ l.cantidad }} x {{ l.precio | dinero }}</td><td class="der">{{ l.importe | dinero }}</td></tr>
          }
        </table>
        <div class="linea"></div>
        <table>
          @if (+d.venta.descuento > 0) {
            <tr><td>Subtotal</td><td class="der">{{ d.venta.subtotal | dinero }}</td></tr>
            <tr><td>Descuento</td><td class="der">-{{ d.venta.descuento | dinero }}</td></tr>
          }
          <tr class="grande"><td>TOTAL</td><td class="der">{{ d.venta.total | dinero }}</td></tr>
          <tr><td>{{ metodoTexto(d.venta.metodo) }}</td><td class="der">{{ d.venta.recibido !== null ? (d.venta.recibido | dinero) : '' }}</td></tr>
          @if (d.venta.recibido !== null) {
            <tr><td>Cambio</td><td class="der">{{ d.venta.cambio | dinero }}</td></tr>
          }
        </table>
        <div class="linea"></div>
        <div class="centro pie">{{ d.pie }}</div>
      </div>
    }
  `,
})
export class VentaTicket implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  readonly t = signal<Ticket | null>(null);
  readonly metodoTexto = metodoTexto;

  ngOnInit(): void {
    this.api.get<Ticket>(`ventas/${this.route.snapshot.paramMap.get('id')}/ticket`).subscribe((d) => this.t.set(d));
  }

  folio(n: number): string {
    return String(n).padStart(5, '0');
  }

  imprimir(): void {
    window.print();
  }
}

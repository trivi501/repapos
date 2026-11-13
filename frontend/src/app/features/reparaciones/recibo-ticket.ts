import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import QRCode from 'qrcode';
import { ApiService } from '../../core/services/api.service';
import { DineroPipe, FechaPipe } from '../../shared/ui';
import type { DatosRecibo } from './recibo';

/** Comprobante de recepción en ticket para impresora térmica de 80 mm (sin PIN ni patrón). */
@Component({
  selector: 'app-recibo-ticket',
  imports: [RouterLink, DineroPipe, FechaPipe],
  styleUrl: './recibo-ticket.scss',
  template: `
    @if (d(); as r) {
      <div class="botones no-imprimir">
        <button class="btn btn-primary" (click)="imprimir()">Imprimir</button>
        <a class="btn btn-secondary" [routerLink]="['/reparaciones', r.orden.id, 'recibo-hoja']">Ver en hoja carta</a>
        <a class="btn btn-secondary" [routerLink]="['/reparaciones', r.orden.id]">← Regresar</a>
      </div>

      <div class="ticket">
        <div class="centro">
          @if (r.taller.logo_url) {
            <img class="logo" [src]="r.taller.logo_url" alt="" />
          }
          <div class="grande">{{ r.taller.nombre }}</div>
          @if (r.orden.sucursal?.direccion) {
            <div>{{ r.orden.sucursal?.direccion }}</div>
          }
          @if (r.orden.sucursal?.telefono || r.taller.whatsapp) {
            <div>Tel. {{ r.orden.sucursal?.telefono || r.taller.whatsapp }}</div>
          }
          @if (r.orden.sucursal?.nombre) {
            <div>Sucursal {{ r.orden.sucursal?.nombre }}</div>
          }
        </div>

        <div class="linea"></div>
        <div class="centro">
          <div>ORDEN DE SERVICIO</div>
          <div class="folio">N° {{ folio(r.orden.folio) }}</div>
          <div>Recibido: {{ r.orden.fecha_recepcion || r.orden.created_at | fecha: true }}</div>
          @if (r.orden.fecha_entrega_estimada) {
            <div>Promesa: {{ r.orden.fecha_entrega_estimada | fecha }}</div>
          }
        </div>

        <div class="linea"></div>
        <div><b>Cliente:</b> {{ r.orden.cliente?.nombre }} {{ r.orden.cliente?.apellido }}</div>
        <div><b>Tel:</b> {{ r.orden.cliente?.telefono }}{{ r.orden.cliente?.telefono2 ? ' / ' + r.orden.cliente?.telefono2 : '' }}</div>
        <div class="chico">{{ r.aviso }}</div>

        <div class="linea"></div>
        <div><b>Equipo:</b> {{ r.orden.equipo?.marca }} {{ r.orden.equipo?.modelo }} {{ r.orden.equipo?.color }}</div>
        @if (r.orden.equipo?.imei) {
          <div><b>IMEI:</b> {{ r.orden.equipo?.imei }}</div>
        }
        <div><b>Falla:</b> {{ r.orden.falla_reportada }}</div>
        @if (r.orden.observaciones) {
          <div><b>Observación:</b> {{ r.orden.observaciones }}</div>
        }
        @if (revisados(r).length) {
          <div class="chico"><b>Revisión:</b> {{ revisados(r).join(' · ') }}</div>
        }
        <div class="chico">Password: {{ r.orden.tiene_password ? 'registrado (no se imprime)' : 'no proporcionado' }}</div>

        <div class="linea"></div>
        <table>
          <tr><td>TOTAL</td><td class="der">{{ r.orden.total | dinero }}</td></tr>
          <tr><td>ANTICIPO</td><td class="der">{{ r.orden.pagado | dinero }}</td></tr>
          <tr class="grande"><td>RESTA</td><td class="der">{{ r.orden.saldo | dinero }}</td></tr>
        </table>

        @if (qr()) {
          <div class="linea"></div>
          <div class="centro">
            <img class="qr" [src]="qr()" alt="Código QR de la orden" />
            <div class="chico">Escanea para ver el estatus de tu reparación</div>
          </div>
        }

        <div class="linea"></div>
        <div class="chico"><b>CLÁUSULAS ¡IMPORTANTE LEER!</b></div>
        <ol class="clausulas">
          @for (t of terminos(r); track t) {
            <li>{{ t }}</li>
          }
        </ol>

        <div class="firma">Acepto términos y condiciones</div>
        <div class="firma">Firma de entrega{{ r.orden.fecha_entrega_real ? ' · ' + (r.orden.fecha_entrega_real | fecha: true) : '' }}</div>
        <div class="centro chico separado">Presente este ticket para recoger su equipo</div>
      </div>
    }
  `,
})
export class ReciboTicket implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  readonly d = signal<DatosRecibo | null>(null);
  readonly qr = signal<string | null>(null);

  ngOnInit(): void {
    this.api.get<DatosRecibo>(`ordenes/${this.route.snapshot.paramMap.get('id')}/recibo`).subscribe(async (d) => {
      this.d.set(d);
      this.qr.set(await QRCode.toDataURL(d.enlace, { width: 180, margin: 1 }));
    });
  }

  folio(n: number | undefined): string {
    return String(n ?? 0).padStart(4, '0');
  }

  /** Solo los puntos de la revisión que se marcaron: "Batería Sí · Golpes No". */
  revisados(r: DatosRecibo): string[] {
    return Object.entries(r.revision_campos)
      .filter(([clave]) => r.orden.revision?.[clave])
      .map(([clave, texto]) => `${texto} ${r.orden.revision?.[clave] === 'si' ? 'Sí' : 'No'}`);
  }

  terminos(r: DatosRecibo): string[] {
    return r.terminos.split('\n').map((t) => t.trim()).filter(Boolean);
  }

  imprimir(): void {
    window.print();
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, input, output, signal } from '@angular/core';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, Estatus, FechaPipe } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';
import type { Telefono } from './telefonos';

type Detalle = Telefono & { pagos?: { id: number; fecha: string; concepto: string; metodo: string; monto: string }[] };

/** Detalles de un teléfono de locatario, solo para ver (clic derecho → Ver detalles, o doble clic en la fila). */
@Component({
  selector: 'app-telefono-detalle',
  imports: [DineroPipe, FechaPipe, Estatus],
  host: { '(document:keydown.escape)': 'cerrar.emit()' },
  template: `
    <div class="modal-backdrop" (mousedown)="alPresionar($event)" (click)="alSoltar($event)">
      <div class="modal modal-detalle" role="dialog" aria-modal="true" aria-labelledby="titulo-detalle">
        <div class="modal-header">
          <h2 id="titulo-detalle">Teléfono #{{ telefonoId() }}</h2>
          <button class="btn btn-secondary" type="button" (click)="cerrar.emit()" aria-label="Cerrar">✕</button>
        </div>

        @if (t(); as x) {
          <div class="estatus-fila">
            <app-estatus [valor]="x.prioridad" />
            <app-estatus [valor]="x.resultado" />
            <app-estatus [valor]="x.estado_pago" />
            <app-estatus [valor]="x.entregado ? 'ENTREGADO' : 'POR ENTREGAR'" />
          </div>

          <div class="rejilla-2 datos">
            <div><span class="etiqueta-campo">Locatario</span><div class="grande"><strong>{{ x.locatario?.nombre }}</strong></div>
              <div class="tenue">{{ x.locatario?.whatsapp || 'Sin WhatsApp' }}</div></div>
            <div><span class="etiqueta-campo">Modelo</span><div class="grande"><strong>{{ x.modelo }}</strong></div></div>
            <div><span class="etiqueta-campo">Serie</span><div class="codigo">{{ x.serie || '—' }}</div></div>
            <div><span class="etiqueta-campo">IMEI</span><div class="codigo">{{ x.imei || '—' }}</div></div>
            <div>
              <span class="etiqueta-campo">PIN / contraseña</span>
              @if (!x.pin) {
                <div class="tenue">Sin PIN</div>
              } @else if (verPin()) {
                <div class="codigo">{{ x.pin }}</div>
              } @else {
                <button class="btn btn-secondary btn-sm" type="button" (click)="verPin.set(true)">Mostrar PIN</button>
              }
            </div>
            <div><span class="etiqueta-campo">Trabajo</span><div>{{ x.trabajo || '—' }}</div></div>
            @if (x.resultado === 'NO QUEDÓ') {
              <div class="ocupa-todo"><span class="etiqueta-campo">Por qué no quedó</span><div class="debe"><strong>{{ x.motivo_no_quedo || 'Sin motivo registrado' }}</strong></div></div>
            }
            <div><span class="etiqueta-campo">Recibido</span><div>{{ x.fecha_recibido | fecha }}</div></div>
            <div><span class="etiqueta-campo">Entregado</span><div>{{ x.fecha_entrega ? (x.fecha_entrega | fecha: true) : 'Todavía no' }}</div></div>
            @if (x.notas) {
              <div class="ocupa-todo"><span class="etiqueta-campo">Notas</span><div class="texto-renglones">{{ x.notas }}</div></div>
            }
          </div>

          <div class="card contenido montos">
            <div class="linea-monto"><span>Total</span><b>{{ x.total | dinero }}</b></div>
            <div class="linea-monto"><span>Pagado</span><b>{{ x.pagado | dinero }}</b></div>
            <div class="linea-monto total"><span>Saldo</span><b [class.debe]="x.saldo > 0" [class.a-favor]="x.saldo <= 0">{{ x.saldo | dinero }}</b></div>
            @if (x.ganancia !== undefined) {
              <div class="linea-monto separador-superior"><span>🔒 Gasto (créditos / servidor / pieza)</span><span>{{ x.costo != null ? (x.costo | dinero) : '—' }}</span></div>
              <div class="linea-monto"><span>🔒 Ganancia</span><b [class.a-favor]="x.ganancia >= 0" [class.debe]="x.ganancia < 0">{{ x.ganancia | dinero }}</b></div>
            }
          </div>

          <h3>Cobros</h3>
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Fecha</th><th>Concepto</th><th>Forma</th><th class="der">Monto</th></tr></thead>
              <tbody>
                @for (p of x.pagos ?? []; track p.id) {
                  <tr>
                    <td>{{ p.fecha | fecha: true }}</td>
                    <td>{{ p.concepto }}</td>
                    <td>{{ metodoTexto(p.metodo) }}</td>
                    <td class="der" [class.debe]="+p.monto < 0">{{ p.monto | dinero }}</td>
                  </tr>
                } @empty {
                  <tr><td colspan="4"><div class="empty-state">Sin cobros</div></td></tr>
                }
              </tbody>
            </table>
          </div>

          <div class="modal-actions">
            @if (x.aviso_url) {
              <a class="btn btn-whatsapp" [href]="x.aviso_url" target="_blank" rel="noopener">💬 Avisar por WhatsApp</a>
            }
            @if (auth.tienePermiso('telefonos.editar')) {
              <button class="btn btn-secondary" type="button" (click)="editar.emit(x.id)">✏️ Editar</button>
            }
            <button class="btn btn-primary" type="button" (click)="cerrar.emit()">Cerrar</button>
          </div>
        } @else {
          <p class="tenue">Cargando…</p>
        }
      </div>
    </div>
  `,
  styles: `
    .modal-detalle { max-width: 720px; }
    .estatus-fila { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.75rem; }
    .datos { gap: 0.75rem 1.25rem; margin-bottom: 1rem; }
    .montos { margin-bottom: 1rem; }
  `,
})
export class TelefonoDetalle implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly telefonoId = input.required<number>();
  readonly cerrar = output<void>();
  /** Pasar a la ventana de edición. */
  readonly editar = output<number>();

  readonly t = signal<Detalle | null>(null);
  readonly verPin = signal(false);
  readonly metodoTexto = metodoTexto;
  presiono = false;

  // métodos void a propósito: si el manejador en la plantilla da `false`, Angular cancela el evento
  // (un mousedown cancelado no deja seleccionar ni copiar el texto)
  alPresionar(e: MouseEvent): void {
    this.presiono = e.target === e.currentTarget;
  }

  alSoltar(e: MouseEvent): void {
    if (this.presiono && e.target === e.currentTarget) this.cerrar.emit();
    this.presiono = false;
  }

  ngOnInit(): void {
    this.api.get<Detalle>(`telefonos/${this.telefonoId()}`).subscribe({
      next: (t) => this.t.set(t),
      error: (e: HttpErrorResponse) => {
        this.avisos.fallo(e);
        this.cerrar.emit();
      },
    });
  }
}

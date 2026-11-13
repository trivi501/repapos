import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';
import { CorteDatos, diaLargo, esperado, horario, textoDiferencia } from './corte-datos';

/** Corte de caja para impresora térmica de 80 mm (también se ve bien en 58 mm). */
@Component({
  selector: 'app-corte-ticket',
  imports: [DineroPipe, FechaPipe],
  styleUrl: '../ventas/venta-ticket.scss',
  template: `
    @if (d(); as x) {
      <div class="botones no-imprimir">
        <button class="btn btn-primary" (click)="imprimir()">Imprimir</button>
        <button class="btn btn-secondary" (click)="cerrar()">Cerrar</button>
      </div>
      <div class="ticket">
        <div class="centro">
          @if (taller()?.logo_url) {
            <img [src]="taller()?.logo_url" alt="" class="logo" />
          }
          <div class="grande">{{ taller()?.nombre }}</div>
          <div class="grande">CORTE DE CAJA</div>
          <div>{{ diaLargo(x.dia) }}</div>
          <div>Turno {{ x.turno }} · {{ horario(x, x.abierto) }}</div>
          @if (x.abierto) {
            <div>*** TURNO SIN CERRAR ***</div>
          }
        </div>
        <div class="linea"></div>
        <div><b>COBROS POR ÁREA</b></div>
        <table>
          @for (a of x.desglose; track a.area) {
            <tr><td>{{ a.texto }} ({{ a.cobros }})</td><td class="der">{{ a.total | dinero }}</td></tr>
          } @empty {
            <tr><td colspan="2">Sin cobros</td></tr>
          }
        </table>
        <div class="linea"></div>
        <table>
          <tr><td>Efectivo</td><td class="der">{{ x.entradas | dinero }}</td></tr>
          @for (o of otros(x); track o.metodo) {
            <tr><td>{{ o.texto }} (fuera de caja)</td><td class="der">{{ o.monto | dinero }}</td></tr>
          }
        </table>
        <div class="linea"></div>
        <div><b>CAJA</b></div>
        <table>
          <tr><td>Fondo inicial</td><td class="der">{{ x.fondo | dinero }}</td></tr>
          <tr><td>+ Efectivo</td><td class="der">{{ x.entradas | dinero }}</td></tr>
          <tr><td>- Salidas ({{ x.salidas.length }})</td><td class="der">{{ x.total_salidas | dinero }}</td></tr>
          <tr class="grande"><td>DEBE HABER</td><td class="der">{{ deberia(x) | dinero }}</td></tr>
          @if (x.corte; as c) {
            <tr><td>Contado</td><td class="der">{{ c.contado | dinero }}</td></tr>
            <tr class="grande"><td colspan="2" class="centro">{{ textoDif(+c.diferencia) }}</td></tr>
            <tr><td>Retiro</td><td class="der">{{ c.retiro | dinero }}</td></tr>
            <tr><td>Queda de fondo</td><td class="der">{{ c.fondo_siguiente ?? x.fondo | dinero }}</td></tr>
          }
        </table>
        @if (x.salidas.length) {
          <div class="linea"></div>
          <div><b>SALIDAS</b></div>
          <table>
            @for (s of x.salidas; track s.id) {
              <tr><td>{{ s.concepto }}</td><td class="der">{{ s.monto | dinero }}</td></tr>
            }
          </table>
        }
        @if (x.por_empleado.length > 1) {
          <div class="linea"></div>
          <div><b>POR EMPLEADO</b></div>
          <table>
            @for (e of x.por_empleado; track e.nombre) {
              <tr><td>{{ e.nombre }}</td><td class="der">{{ e.efectivo + e.otros | dinero }}</td></tr>
            }
          </table>
        }
        @if (x.corte?.notas) {
          <div class="linea"></div>
          <div>Notas: {{ x.corte?.notas }}</div>
        }
        <div class="linea"></div>
        <div class="centro">
          @if (x.corte; as c) {
            Corte por {{ c.user?.name }} · {{ c.updated_at | fecha: true }}
          }
          <br /><br />_______________________<br />Firma
        </div>
      </div>
    }
  `,
})
export class CorteTicket implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly auth = inject(AuthService);

  readonly d = signal<CorteDatos | null>(null);
  readonly taller = () => this.auth.usuarioActual()?.taller;
  readonly diaLargo = diaLargo;
  readonly horario = horario;

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    this.api.get<CorteDatos>('cortes', { fecha: q.get('fecha') ?? '', turno: q.get('turno') ?? '' }).subscribe({
      next: (d) => {
        this.d.set(d);
        setTimeout(() => window.print(), 300);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  otros(x: CorteDatos) {
    return Object.entries(x.otros_metodos).map(([metodo, monto]) => ({ metodo, monto, texto: metodoTexto(metodo) }));
  }

  deberia(x: CorteDatos): number {
    return esperado(x, x.fondo);
  }

  textoDif(dif: number): string {
    return textoDiferencia(dif);
  }

  imprimir(): void {
    window.print();
  }

  cerrar(): void {
    window.close();
  }
}

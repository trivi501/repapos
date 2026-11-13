import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, dinero, fechaISO } from '../../shared/ui';

interface Fila {
  id: number;
  fecha: string;
  turno: number;
  fondo: string;
  entradas: string;
  salidas: string;
  esperado: string;
  contado: string;
  diferencia: string;
  retiro: string;
  fondo_siguiente: string | null;
  notas: string;
  updated_at: string;
  user?: { name: string } | null;
}

interface FilaResumen {
  clave: string;
  etiqueta: string;
  desde: string;
  hasta: string;
  efectivo: number;
  tarjeta: number;
  transferencia: number;
  total: number;
  salidas: number;
  cortes: number;
  faltantes: number;
  sobrantes: number;
  retiros: number;
}

@Component({
  selector: 'app-corte-historial',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  template: `
    <div class="encabezado">
      <h1>Historial de cortes</h1>
      <a class="btn btn-secondary" routerLink="/corte">← Corte de hoy</a>
    </div>

    <!-- resumen por semana o mes -->
    <div class="card filtros">
      <div class="form-field">
        <label for="agrupar">Resumen por</label>
        <select id="agrupar" [(ngModel)]="filtros.agrupar" (ngModelChange)="cambiarAgrupacion()">
          <option value="semana">Semana</option>
          <option value="mes">Mes</option>
        </select>
      </div>
      <div class="form-field">
        <label for="desde">Desde</label>
        <input id="desde" type="date" [(ngModel)]="filtros.desde" />
      </div>
      <div class="form-field">
        <label for="hasta">Hasta</label>
        <input id="hasta" type="date" [(ngModel)]="filtros.hasta" />
      </div>
      <button class="btn btn-secondary" (click)="cargarResumen()">Ver</button>
      <button class="btn btn-secondary" (click)="exportar()">Exportar a Excel</button>
    </div>

    <div class="card tabla-scroll separado-abajo">
      <table>
        <thead>
          <tr><th>{{ filtros.agrupar === 'mes' ? 'Mes' : 'Semana' }}</th><th class="der">Efectivo</th><th class="der">Tarjeta</th>
            <th class="der">Transferencia</th><th class="der">Total cobrado</th><th class="der">Salidas</th><th class="der">Cortes</th>
            <th class="der">Faltantes</th><th class="der">Sobrantes</th><th class="der">Retiros</th></tr>
        </thead>
        <tbody>
          @for (f of resumen(); track f.clave) {
            <tr>
              <td class="sin-salto">{{ f.etiqueta }}</td>
              <td class="der">{{ f.efectivo | dinero }}</td>
              <td class="der">{{ f.tarjeta | dinero }}</td>
              <td class="der">{{ f.transferencia | dinero }}</td>
              <td class="der"><b>{{ f.total | dinero }}</b></td>
              <td class="der">{{ f.salidas | dinero }}</td>
              <td class="der">{{ f.cortes }}</td>
              <td class="der" [class.debe]="f.faltantes > 0">{{ f.faltantes | dinero }}</td>
              <td class="der" [class.sobra]="f.sobrantes > 0">{{ f.sobrantes | dinero }}</td>
              <td class="der">{{ f.retiros | dinero }}</td>
            </tr>
          } @empty {
            <tr><td colspan="10"><div class="empty-state">Sin movimientos en estas fechas.</div></td></tr>
          }
        </tbody>
        @if (resumen().length > 1) {
          @let t = totales();
          <tfoot>
            <tr class="fila-total">
              <td><b>Total</b></td>
              <td class="der"><b>{{ t.efectivo | dinero }}</b></td>
              <td class="der"><b>{{ t.tarjeta | dinero }}</b></td>
              <td class="der"><b>{{ t.transferencia | dinero }}</b></td>
              <td class="der"><b>{{ t.total | dinero }}</b></td>
              <td class="der"><b>{{ t.salidas | dinero }}</b></td>
              <td class="der"><b>{{ t.cortes }}</b></td>
              <td class="der debe"><b>{{ t.faltantes | dinero }}</b></td>
              <td class="der sobra"><b>{{ t.sobrantes | dinero }}</b></td>
              <td class="der"><b>{{ t.retiros | dinero }}</b></td>
            </tr>
          </tfoot>
        }
      </table>
    </div>

    <!-- cada corte -->
    <h2>Cortes guardados</h2>
    <div class="card tabla-scroll">
      <table>
        <thead>
          <tr><th>Día</th><th>Turno</th><th class="der">Fondo</th><th class="der">Entradas</th><th class="der">Salidas</th>
            <th class="der">Debía haber</th><th class="der">Contado</th><th class="der">Diferencia</th>
            <th class="der">Retiro</th><th class="der">Dejó de fondo</th><th>Notas</th><th>Hecho</th></tr>
        </thead>
        <tbody>
          @for (c of cortes(); track c.id) {
            <tr>
              <td class="sin-salto"><a routerLink="/corte" [queryParams]="{ fecha: dia(c), turno: c.turno }">{{ dia(c) | fecha }}</a></td>
              <td>{{ c.turno }}</td>
              <td class="der">{{ c.fondo | dinero }}</td>
              <td class="der">{{ c.entradas | dinero }}</td>
              <td class="der">{{ c.salidas | dinero }}</td>
              <td class="der">{{ c.esperado | dinero }}</td>
              <td class="der">{{ c.contado | dinero }}</td>
              <td class="der" [class.a-favor]="+c.diferencia === 0" [class.debe]="+c.diferencia < 0" [class.sobra]="+c.diferencia > 0"><strong>{{ textoDif(c) }}</strong></td>
              <td class="der">{{ c.retiro | dinero }}</td>
              <td class="der">{{ c.fondo_siguiente ?? c.fondo | dinero }}</td>
              <td class="tenue">{{ c.notas }}</td>
              <td class="sin-salto tenue">{{ c.updated_at | fecha: true }}{{ c.user ? ' · ' + c.user.name : '' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="12"><div class="empty-state">Todavía no hay cortes guardados.</div></td></tr>
          }
        </tbody>
      </table>
      <div class="pagination">
        <button class="btn btn-secondary" (click)="ir(pagina() - 1)" [disabled]="pagina() <= 1">Anterior</button>
        <span>Página {{ pagina() }} de {{ ultima() }}</span>
        <button class="btn btn-secondary" (click)="ir(pagina() + 1)" [disabled]="pagina() >= ultima()">Siguiente</button>
      </div>
    </div>
  `,
})
export class CorteHistorial implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);

  readonly cortes = signal<Fila[]>([]);
  readonly pagina = signal(1);
  readonly ultima = signal(1);
  readonly resumen = signal<FilaResumen[]>([]);

  filtros = { agrupar: 'semana', desde: '', hasta: '' };

  ngOnInit(): void {
    this.cargarResumen();
    this.ir(1);
  }

  /** Al cambiar de semana a mes se vuelve al rango de fábrica (12 semanas o 12 meses). */
  cambiarAgrupacion(): void {
    this.filtros.desde = '';
    this.filtros.hasta = '';
    this.cargarResumen();
  }

  cargarResumen(): void {
    this.api.get<{ filas: FilaResumen[]; desde: string; hasta: string }>('cortes/resumen', this.filtros).subscribe({
      next: (r) => {
        this.filtros.desde = r.desde;
        this.filtros.hasta = r.hasta;
        this.resumen.set(r.filas);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  totales() {
    const s = (c: keyof FilaResumen) => this.resumen().reduce((t, f) => t + Number(f[c]), 0);
    return {
      efectivo: s('efectivo'), tarjeta: s('tarjeta'), transferencia: s('transferencia'), total: s('total'), salidas: s('salidas'),
      cortes: s('cortes'), faltantes: s('faltantes'), sobrantes: s('sobrantes'), retiros: s('retiros'),
    };
  }

  exportar(): void {
    this.api.descargar('cortes/exportar', `cortes_${fechaISO()}.csv`, this.filtros);
  }

  ir(pagina: number): void {
    this.api.get<{ data: Fila[]; current_page: number; last_page: number }>('cortes/historial', { page: pagina }).subscribe({
      next: (r) => {
        this.cortes.set(r.data);
        this.pagina.set(r.current_page);
        this.ultima.set(r.last_page);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  /** El API manda la fecha con hora; el corte es de un día. */
  dia(c: Fila): string {
    return c.fecha.slice(0, 10);
  }

  textoDif(c: Fila): string {
    const d = Number(c.diferencia);
    if (d === 0) return 'Cuadra';
    return (d > 0 ? 'Sobra ' : 'Falta ') + dinero(Math.abs(d));
  }
}

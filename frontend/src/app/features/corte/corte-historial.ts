import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, dinero } from '../../shared/ui';

interface Fila {
  id: number;
  fecha: string;
  fondo: string;
  entradas: string;
  salidas: string;
  esperado: string;
  contado: string;
  diferencia: string;
  notas: string;
  updated_at: string;
  user?: { name: string } | null;
}

@Component({
  selector: 'app-corte-historial',
  imports: [RouterLink, DineroPipe, FechaPipe],
  template: `
    <div class="encabezado">
      <h1>Historial de cortes</h1>
      <a class="btn btn-secondary" routerLink="/corte">← Corte de hoy</a>
    </div>
    <div class="card tabla-scroll">
      <table>
        <thead>
          <tr><th>Día</th><th class="der">Fondo</th><th class="der">Entradas</th><th class="der">Salidas</th>
            <th class="der">Debía haber</th><th class="der">Contado</th><th class="der">Diferencia</th><th>Notas</th><th>Hecho</th></tr>
        </thead>
        <tbody>
          @for (c of cortes(); track c.id) {
            <tr>
              <td class="sin-salto"><a routerLink="/corte" [queryParams]="{ fecha: dia(c) }">{{ dia(c) | fecha }}</a></td>
              <td class="der">{{ c.fondo | dinero }}</td>
              <td class="der">{{ c.entradas | dinero }}</td>
              <td class="der">{{ c.salidas | dinero }}</td>
              <td class="der">{{ c.esperado | dinero }}</td>
              <td class="der">{{ c.contado | dinero }}</td>
              <td class="der" [class.a-favor]="+c.diferencia === 0" [class.debe]="+c.diferencia < 0" [class.sobra]="+c.diferencia > 0"><strong>{{ textoDif(c) }}</strong></td>
              <td class="tenue">{{ c.notas }}</td>
              <td class="sin-salto tenue">{{ c.updated_at | fecha: true }}{{ c.user ? ' · ' + c.user.name : '' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="9"><div class="empty-state">Todavía no hay cortes guardados.</div></td></tr>
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

  ngOnInit(): void {
    this.ir(1);
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

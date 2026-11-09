import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe } from '../../shared/ui';

interface Movimiento {
  id: number;
  created_at: string;
  tipo: string;
  cantidad: number;
  existencia: number;
  importe: string;
  nota: string;
  user?: { name: string } | null;
}

@Component({
  selector: 'app-kardex',
  imports: [RouterLink, DineroPipe, FechaPipe],
  template: `
    <div class="encabezado">
      <h1>{{ producto()?.nombre }} <small class="tenue">· existencia {{ producto()?.hay }}</small></h1>
      <a class="btn btn-secondary" routerLink="/inventario">← Inventario</a>
    </div>
    <div class="card tabla-scroll">
      <table>
        <thead><tr><th>Fecha</th><th>Tipo</th><th class="der">Piezas</th><th class="der">Quedaron</th><th class="der">Importe</th><th>Nota</th><th>Usuario</th></tr></thead>
        <tbody>
          @for (m of movimientos(); track m.id) {
            <tr>
              <td class="sin-salto">{{ m.created_at | fecha: true }}</td>
              <td>{{ m.tipo }}</td>
              <td class="der" [class.debe]="m.cantidad < 0" [class.a-favor]="m.cantidad > 0"><strong>{{ m.cantidad > 0 ? '+' : '' }}{{ m.cantidad }}</strong></td>
              <td class="der">{{ m.existencia }}</td>
              <td class="der">{{ +m.importe ? (m.importe | dinero) : '' }}</td>
              <td class="tenue">{{ m.nota }}</td>
              <td class="tenue">{{ m.user?.name }}</td>
            </tr>
          } @empty {
            <tr><td colspan="7"><div class="empty-state">Sin movimientos en esta sucursal.</div></td></tr>
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
export class Kardex implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);

  readonly producto = signal<{ nombre: string; hay: number } | null>(null);
  readonly movimientos = signal<Movimiento[]>([]);
  readonly pagina = signal(1);
  readonly ultima = signal(1);

  ngOnInit(): void {
    this.ir(1);
  }

  ir(pagina: number): void {
    this.api
      .get<{ producto: { nombre: string; hay: number }; movimientos: { data: Movimiento[]; current_page: number; last_page: number } }>(
        `inventario/${this.route.snapshot.paramMap.get('id')}/kardex`,
        { page: pagina },
      )
      .subscribe({
        next: (r) => {
          this.producto.set(r.producto);
          this.movimientos.set(r.movimientos.data);
          this.pagina.set(r.movimientos.current_page);
          this.ultima.set(r.movimientos.last_page);
        },
        error: (e: HttpErrorResponse) => this.avisos.fallo(e),
      });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, Injectable, OnInit, inject, input, output, signal } from '@angular/core';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DialogoService } from '../../core/services/dialogo.service';
import { dinero } from '../ui';

export interface PlantillaServicio {
  id: number;
  nombre: string;
  precio: number;
}

/** Lista de plantillas compartida por todos los formularios (se pide una vez a la API). */
@Injectable({ providedIn: 'root' })
export class PlantillasServicioService {
  private readonly api = inject(ApiService);
  readonly lista = signal<PlantillaServicio[]>([]);
  private cargada = false;

  cargar(forzar = false): void {
    if (this.cargada && !forzar) return;
    this.cargada = true;
    this.api.get<PlantillaServicio[]>('plantillas-servicio').subscribe({
      next: (l) => this.lista.set(l),
      error: () => (this.cargada = false),
    });
  }

  guardar(nombre: string, precio: number) {
    return this.api.post<{ message: string; plantilla: PlantillaServicio }>('plantillas-servicio', { nombre, precio });
  }

  quitar(p: PlantillaServicio) {
    return this.api.delete<{ message: string }>(`plantillas-servicio/${p.id}`);
  }
}

/**
 * Elegir un servicio de los que el taller hace seguido (FRP por ISP $250, FRP normal $100…)
 * llena el trabajo y el precio. Lo que se escribe en el formulario se puede guardar como plantilla nueva.
 */
@Component({
  selector: 'app-plantillas-servicio',
  standalone: true,
  template: `
    <div class="plantillas">
      <select [attr.aria-label]="'Plantilla de servicio'" (change)="elegir($any($event.target))">
        <option value="">📋 {{ plantillas.lista().length ? 'Usar plantilla de servicio…' : 'Aún no hay plantillas de servicio' }}</option>
        @for (p of plantillas.lista(); track p.id) {
          <option [value]="p.id">{{ p.nombre }} · {{ precio(p.precio) }}</option>
        }
      </select>
      <button type="button" class="btn btn-secondary btn-sm" (click)="guardar()" [disabled]="!nombre().trim()"
              title="Guarda el trabajo y el precio escritos para elegirlos después de un clic">💾 Guardar como plantilla</button>
      @if (plantillas.lista().length) {
        <button type="button" class="btn btn-secondary btn-sm" (click)="editando.set(!editando())" [attr.aria-expanded]="editando()">
          {{ editando() ? 'Listo' : '⚙️' }}
        </button>
      }
    </div>
    @if (editando()) {
      <ul class="plantillas-lista">
        @for (p of plantillas.lista(); track p.id) {
          <li>
            <span>{{ p.nombre }}</span>
            <strong>{{ precio(p.precio) }}</strong>
            <button type="button" class="icono-btn" (click)="quitar(p)" [attr.aria-label]="'Quitar plantilla ' + p.nombre" title="Quitar plantilla">🗑️</button>
          </li>
        }
      </ul>
    }
  `,
  styles: `
    .plantillas { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin-top: 0.35rem; }
    .plantillas select { flex: 1 1 14rem; min-width: 0; padding: 0.35rem 0.5rem; border: 1px dashed var(--color-primary);
      border-radius: 6px; background: var(--tint-primary); color: var(--color-text); font: inherit; font-size: 0.85rem; }
    .plantillas-lista { list-style: none; margin: 0.4rem 0 0; padding: 0.25rem 0.5rem; border: 1px solid var(--color-border);
      border-radius: 6px; font-size: 0.85rem; }
    .plantillas-lista li { display: flex; gap: 0.5rem; align-items: center; padding: 0.15rem 0; }
    .plantillas-lista span { flex: 1; }
  `,
})
export class PlantillasServicio implements OnInit {
  readonly plantillas = inject(PlantillasServicioService);
  private readonly avisos = inject(AvisosService);
  private readonly dialogo = inject(DialogoService);

  /** Lo que está escrito ahora en el formulario (para "Guardar como plantilla"). */
  readonly nombre = input<string>('');
  readonly precioActual = input<number | string | null>(null);
  readonly elegido = output<PlantillaServicio>();
  readonly editando = signal(false);

  ngOnInit(): void {
    this.plantillas.cargar();
  }

  precio(v: number): string {
    return dinero(v);
  }

  elegir(select: HTMLSelectElement): void {
    const p = this.plantillas.lista().find((x) => String(x.id) === select.value);
    select.value = '';
    if (p) this.elegido.emit(p);
  }

  guardar(): void {
    const nombre = this.nombre().trim();
    if (!nombre) return;
    const precio = Number(this.precioActual()) || 0;
    this.plantillas.guardar(nombre, precio).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.plantillas.cargar(true);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  async quitar(p: PlantillaServicio): Promise<void> {
    if (!(await this.dialogo.confirmar(`¿Quitar la plantilla "${p.nombre}"? Las órdenes ya hechas no cambian.`, { titulo: 'Quitar plantilla', aceptar: 'Quitar', peligro: true }))) return;
    this.plantillas.quitar(p).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.plantillas.cargar(true);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

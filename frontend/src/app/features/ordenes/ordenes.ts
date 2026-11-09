import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { OrdenService } from '../../core/services/orden.service';
import { AuthService } from '../../core/services/auth.service';
import { ESTADOS_ORDEN, OrdenReparacion } from '../../core/models/taller.model';
import { DineroPipe, FechaPipe } from '../../shared/ui';

@Component({
  selector: 'app-ordenes',
  standalone: true,
  imports: [RouterLink, DineroPipe, FechaPipe],
  templateUrl: './ordenes.html',
  styleUrl: './ordenes.scss',
})
export class Ordenes implements OnInit {
  private readonly ordenService = inject(OrdenService);
  readonly authService = inject(AuthService);

  readonly estados = ESTADOS_ORDEN;

  readonly ordenes = signal<OrdenReparacion[]>([]);
  readonly cargando = signal(false);
  readonly pagina = signal(1);
  readonly totalPaginas = signal(1);
  readonly filtroEstado = signal('abiertas');
  private termino = '';

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.ordenService.listar(this.pagina(), this.filtroEstado(), this.termino).subscribe({
      next: (respuesta) => {
        this.ordenes.set(respuesta.data);
        this.totalPaginas.set(respuesta.last_page);
        this.cargando.set(false);
      },
      error: () => this.cargando.set(false),
    });
  }

  filtrarPorEstado(estado: string): void {
    this.filtroEstado.set(estado);
    this.pagina.set(1);
    this.cargar();
  }

  buscar(texto: string): void {
    this.termino = texto;
    this.pagina.set(1);
    this.cargar();
  }

  cambiarPagina(delta: number): void {
    const nueva = this.pagina() + delta;
    if (nueva < 1 || nueva > this.totalPaginas()) return;
    this.pagina.set(nueva);
    this.cargar();
  }

  etiquetaEstado(estado: string): string {
    return this.estados.find((e) => e.valor === estado)?.etiqueta ?? estado;
  }

  claseEstado(estado: string): string {
    switch (estado) {
      case 'entregado':
      case 'reparado':
        return 'badge-success';
      case 'cancelado':
      case 'no_quedo':
        return 'badge-danger';
      case 'esperando_repuesto':
        return 'badge-warning';
      default:
        return 'badge-primary';
    }
  }
}

import { TablaTarjetas } from '../../shared/tabla-tarjetas';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { OrdenService } from '../../core/services/orden.service';
import { AuthService } from '../../core/services/auth.service';
import { ApiService } from '../../core/services/api.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DialogoService } from '../../core/services/dialogo.service';
import { ESTADOS_ORDEN, OrdenReparacion, estadosPara } from '../../core/models/taller.model';
import { DineroPipe, FechaPipe, dinero } from '../../shared/ui';
import { MOTIVOS_NO_QUEDO } from '../../shared/motivos-no-quedo';

/** Estatus en que el equipo sigue en trabajo (entregarlo así se confirma). */
const EN_TRABAJO = ['recibido', 'diagnostico', 'en_reparacion', 'esperando_repuesto', 'en_proceso'];

@Component({
  selector: 'app-ordenes',
  standalone: true,
  imports: [TablaTarjetas, RouterLink, FormsModule, DineroPipe, FechaPipe],
  templateUrl: './ordenes.html',
  styleUrl: './ordenes.scss',
})
export class Ordenes implements OnInit {
  private readonly ordenService = inject(OrdenService);
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly dialogo = inject(DialogoService);
  private readonly router = inject(Router);
  readonly authService = inject(AuthService);

  readonly estados = ESTADOS_ORDEN;

  readonly ordenes = signal<OrdenReparacion[]>([]);
  readonly cargando = signal(false);
  readonly pagina = signal(1);
  readonly totalPaginas = signal(1);
  readonly filtroEstado = signal('abiertas');
  private termino = '';

  /** Menú del clic derecho (o del botón ⋮) sobre una reparación. */
  readonly menu = signal<{ o: OrdenReparacion; x: number; y: number } | null>(null);
  montoAbono: number | null = null;
  metodoAbono = 'EFECTIVO';

  /** "No quedó" o "Rechazado" piden el motivo (va en el aviso al cliente). */
  readonly pidiendoMotivo = signal<{ o: OrdenReparacion; estado: 'no_quedo' | 'rechazado' } | null>(null);
  readonly motivosNoQuedo = MOTIVOS_NO_QUEDO;
  motivo = '';

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
      case 'reparado':
        return 'badge-success';
      case 'cancelado':
      case 'no_quedo':
      case 'rechazado':
        return 'badge-danger';
      case 'esperando_repuesto':
        return 'badge-warning';
      default:
        return 'badge-primary';
    }
  }

  // ---- menú contextual

  abrirMenu(evento: MouseEvent, o: OrdenReparacion): void {
    evento.preventDefault();
    this.montoAbono = (o.saldo ?? 0) > 0 ? (o.saldo ?? null) : null;
    // que no se salga de la pantalla
    const x = Math.min(evento.clientX, window.innerWidth - 280);
    const y = Math.min(evento.clientY, window.innerHeight - 460);
    this.menu.set({ o, x: Math.max(8, x), y: Math.max(8, y) });
  }

  /** Estatus que se le pueden poner (liberación o reparación), sin el que ya tiene. */
  estadosDe(o: OrdenReparacion) {
    return estadosPara(o.bloqueos).filter((e) => e.valor !== o.estado);
  }

  abrir(o: OrdenReparacion): void {
    this.menu.set(null);
    this.router.navigate(['/reparaciones', o.id]);
  }

  editar(o: OrdenReparacion): void {
    this.menu.set(null);
    this.router.navigate(['/reparaciones', o.id, 'editar']);
  }

  imprimir(o: OrdenReparacion): void {
    this.menu.set(null);
    window.open(this.router.serializeUrl(this.router.createUrlTree(['/reparaciones', o.id, 'recibo'])), '_blank');
  }

  cambiarEstado(o: OrdenReparacion, estado: string): void {
    if (estado === 'no_quedo' || estado === 'rechazado') {
      this.menu.set(null);
      this.motivo = '';
      this.pidiendoMotivo.set({ o, estado });
      return;
    }
    this.accion(o, { accion: 'estatus', estado });
  }

  confirmarMotivo(): void {
    const p = this.pidiendoMotivo();
    const motivo = this.motivo.trim();
    if (!p || !motivo) return;
    this.pidiendoMotivo.set(null);
    this.accion(p.o, { accion: 'estatus', estado: p.estado, motivo });
  }

  async entregar(o: OrdenReparacion): Promise<void> {
    this.menu.set(null);
    if (o.entregado) {
      if (await this.dialogo.confirmar(`¿Quitar la marca de entregado del folio ${o.folio}? Úsalo solo si se marcó por error.`, { titulo: 'Quitar entregado', aceptar: 'Quitar', peligro: true })) {
        this.accion(o, { accion: 'quitar_entrega' });
      }
      return;
    }
    const avisos = [
      EN_TRABAJO.includes(o.estado) ? 'Sigue en trabajo (no está Listo, No quedó ni Rechazado).' : '',
      (o.saldo ?? 0) > 0 ? `Aún debe ${dinero(o.saldo)}.` : '',
    ].filter(Boolean).join('\n');
    if (await this.dialogo.confirmar(`${avisos ? avisos + '\n\n' : ''}¿Entregar el folio ${o.folio}?`, { titulo: 'Entregar', aceptar: 'Entregar' })) {
      this.accion(o, { accion: 'entregar' });
    }
  }

  abonar(o: OrdenReparacion): void {
    if (!this.montoAbono || this.montoAbono <= 0) return;
    this.accion(o, { accion: 'abono', monto: this.montoAbono, metodo: this.metodoAbono });
  }

  async liquidar(o: OrdenReparacion): Promise<void> {
    this.menu.set(null);
    if (await this.dialogo.confirmar(`¿Liquidar ${dinero(o.saldo)} del folio ${o.folio}?`)) {
      this.accion(o, { accion: 'liquidar', metodo: this.metodoAbono });
    }
  }

  private accion(o: OrdenReparacion, cuerpo: Record<string, unknown>): void {
    this.menu.set(null);
    this.api.post<{ message: string; aviso: AvisoWhatsapp | null }>(`ordenes/${o.id}/accion`, cuerpo).subscribe({
      next: (r) => {
        this.avisos.ok(r.message, r.aviso);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

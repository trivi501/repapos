import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DineroPipe, Estatus, FechaPipe, dinero, fechaISO } from '../../shared/ui';
import { TelefonoForm } from './telefono-form';

export interface Telefono {
  id: number;
  prioridad: string;
  fecha_recibido: string;
  modelo: string;
  trabajo: string;
  notas: string;
  total: string;
  pagado: string;
  saldo: number;
  estado_pago: string;
  resultado: string;
  entregado: boolean;
  fecha_entrega: string | null;
  aviso_url: string | null;
  locatario?: { id: number; nombre: string; whatsapp: string | null };
  locatario_id: number;
  cobros_total?: number;
}

interface Respuesta {
  data: Telefono[];
  current_page: number;
  last_page: number;
  resumen: { telefonos: number; en_taller: string; listos: string; no_quedaron: string; total: string; cobrado: string; por_cobrar: string };
  locatarios: { id: number; nombre: string; whatsapp: string | null }[];
}

@Component({
  selector: 'app-telefonos',
  imports: [FormsModule, DineroPipe, FechaPipe, Estatus, TelefonoForm],
  templateUrl: './telefonos.html',
})
export class Telefonos implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly estados = [
    { valor: '', texto: 'Todos' },
    { valor: 'taller', texto: 'En taller' },
    { valor: 'listos', texto: 'Listos (por entregar)' },
    { valor: 'noquedo', texto: 'No quedaron (por regresar)' },
    { valor: 'saldo', texto: 'Con saldo pendiente' },
    { valor: 'entregados_deben', texto: 'Entregados que deben' },
    { valor: 'entregados_pagados', texto: 'Entregados y pagados' },
  ];

  readonly telefonos = signal<Telefono[]>([]);
  readonly locatarios = signal<Respuesta['locatarios']>([]);
  readonly resumen = signal<Respuesta['resumen'] | null>(null);
  readonly cargando = signal(true);
  readonly paginaActual = signal(1);
  readonly ultimaPagina = signal(1);
  readonly menu = signal<{ t: Telefono; x: number; y: number } | null>(null);

  /** Ventana de alta / edición: id null = teléfono nuevo. */
  readonly formulario = signal<{ id: number | null; eliminar: boolean } | null>(null);

  filtros = { locatario: '', estado: '', prioridad: '', q: '', urgentes: false };
  montoAbono: number | null = null;

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    this.filtros.locatario = q.get('locatario') ?? '';
    // las direcciones viejas (/telefonos/nuevo y /telefonos/:id/editar) llegan aquí y abren la ventana
    if (q.has('nuevo')) this.abrirFormulario(null);
    else if (q.get('editar')) this.abrirFormulario(Number(q.get('editar')));
    if (q.has('nuevo') || q.has('editar')) {
      this.router.navigate([], { queryParams: { nuevo: null, editar: null }, queryParamsHandling: 'merge', replaceUrl: true });
    }
    this.cargar();
  }

  abrirFormulario(id: number | null, eliminar = false): void {
    this.menu.set(null);
    this.formulario.set({ id, eliminar });
  }

  cargar(): void {
    this.cargando.set(true);
    this.api.get<Respuesta>('telefonos', { ...this.filtros, page: this.paginaActual() }).subscribe({
      next: (r) => {
        this.telefonos.set(r.data);
        this.resumen.set(r.resumen);
        this.locatarios.set(r.locatarios);
        this.paginaActual.set(r.current_page);
        this.ultimaPagina.set(r.last_page);
        this.cargando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.cargando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  filtrar(): void {
    this.paginaActual.set(1);
    this.cargar();
  }

  pagina(delta: number): void {
    this.paginaActual.update((p) => p + delta);
    this.cargar();
  }

  abrirMenu(evento: MouseEvent, t: Telefono): void {
    evento.preventDefault();
    this.montoAbono = t.saldo > 0 ? t.saldo : null;
    // que no se salga de la pantalla
    const x = Math.min(evento.clientX, window.innerWidth - 280);
    const y = Math.min(evento.clientY, window.innerHeight - 340);
    this.menu.set({ t, x: Math.max(8, x), y: Math.max(8, y) });
  }

  accion(t: Telefono, accion: string, extra: Record<string, unknown> = {}): void {
    this.menu.set(null);
    this.api.post<{ message: string; aviso: AvisoWhatsapp | null }>(`telefonos/${t.id}/accion`, { accion, ...extra }).subscribe({
      next: (r) => {
        this.avisos.ok(r.message, r.aviso);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  entregar(t: Telefono): void {
    const pregunta = t.entregado
      ? 'Ya está entregado. ¿Quitarle la entrega?'
      : t.resultado === 'EN TALLER'
        ? 'Sigue En taller (no está Listo ni No quedó). ¿Entregarlo de todos modos?'
        : null;
    if (pregunta && !confirm(pregunta)) return;
    this.accion(t, 'entregado');
  }

  abonar(t: Telefono): void {
    if (!this.montoAbono || this.montoAbono <= 0) return;
    this.accion(t, 'abono', { monto: this.montoAbono });
  }

  liquidar(t: Telefono): void {
    if (!confirm(`¿Marcar como pagado completo? Se registra un cobro de ${dinero(t.saldo)}.`)) return;
    this.accion(t, 'liquidar');
  }

  exportar(): void {
    this.api.descargar('telefonos/exportar', `telefonos_${fechaISO()}.csv`, this.filtros);
  }
}

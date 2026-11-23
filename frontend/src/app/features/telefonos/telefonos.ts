import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DineroPipe, Estatus, FechaPipe, dinero, fechaISO } from '../../shared/ui';
import { TelefonoForm } from './telefono-form';
import { TelefonoDetalle } from './telefono-detalle';
import { MOTIVOS_NO_QUEDO } from '../../shared/motivos-no-quedo';
import { DetectarUsb, TelefonoUsb, nombreTelefonoUsb } from '../../shared/detectar-usb/detectar-usb';
import { DialogoService } from '../../core/services/dialogo.service';
import { textoPlan, type Elegido } from '../saldos/saldo-plan';

export interface Telefono {
  id: number;
  prioridad: string;
  fecha_recibido: string;
  modelo: string;
  serie?: string | null;
  imei?: string | null;
  /** Solo llega al abrir el teléfono (nunca en la lista). */
  pin?: string | null;
  trabajo: string;
  notas: string;
  total: string;
  /** Gasto y ganancia: solo llegan si el usuario tiene "ordenes.ver_costos". */
  costo?: string | null;
  ganancia?: number;
  pagado: string;
  saldo: number;
  estado_pago: string;
  resultado: string;
  /** Por qué no quedó (va en el aviso por WhatsApp). */
  motivo_no_quedo?: string | null;
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
  resumen: {
    telefonos: number; en_taller: string; listos: string; no_quedaron: string; total: string; cobrado: string; por_cobrar: string;
    gasto?: string; ganancia?: number;
  };
  locatarios: { id: number; nombre: string; whatsapp: string | null }[];
  /** Saldo pendiente del locatario filtrado (teléfonos entregados que debe), listo para mandar por WhatsApp. */
  cuenta: Cuenta | null;
}

interface Cuenta {
  nombre: string;
  telefonos: number;
  debe: number;
  a_pagar: number;
  tiene_whatsapp: boolean;
  url: string;
}

@Component({
  selector: 'app-telefonos',
  imports: [FormsModule, DineroPipe, FechaPipe, Estatus, TelefonoForm, TelefonoDetalle, DetectarUsb],
  templateUrl: './telefonos.html',
  // tabla compacta: caben más teléfonos en pantalla sin desplazarse a los lados
  styles: `
    .tabla-compacta { font-size: 0.8rem; }
    .tabla-compacta thead th { padding: 0.45rem 0.4rem; font-size: 0.68rem; }
    .tabla-compacta tbody td { padding: 0.35rem 0.4rem; }
    .tabla-compacta ::ng-deep .badge { padding: 0.1rem 0.45rem; font-size: 0.68rem; }
    .tabla-compacta .icono-btn { width: 1.6rem; height: 1.6rem; font-size: 0.85rem; }
    /* las acciones en dos renglones: ocupan lo mismo de alto que los tres estatus apilados */
    .tabla-compacta .iconos { flex-wrap: wrap; width: 5rem; gap: 0.1rem; }
    .estatus-apilados { display: flex; flex-direction: column; align-items: flex-start; gap: 0.15rem; }
  `,
})
export class Telefonos implements OnInit {
  private readonly dialogo = inject(DialogoService);
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
  /** Ventana de detalles (solo ver) del teléfono con este id. */
  readonly detalle = signal<number | null>(null);

  verDetalle(id: number): void {
    this.menu.set(null);
    this.detalle.set(id);
  }

  filtros = { locatario: '', estado: '', prioridad: '', q: '', urgentes: false, ident: '' };
  montoAbono: number | null = null;

  /** Teléfono conectado por USB que se está buscando (para ver si ya vino y si entra en garantía). */
  readonly buscandoUsb = signal<TelefonoUsb | null>(null);

  buscarConectado(t: TelefonoUsb): void {
    const ident = [t.serial, t.imei].filter(Boolean).join(',');
    if (!ident) {
      this.avisos.ok('Ese teléfono no reportó serie ni IMEI: búscalo por modelo.');
      return;
    }
    this.buscandoUsb.set(t);
    // se busca en todos: entregados, en taller y de cualquier locatario
    this.filtros = { ...this.filtros, ident, q: '', estado: '', locatario: '' };
    this.filtrar();
  }

  quitarBusquedaUsb(): void {
    this.buscandoUsb.set(null);
    this.filtros = { ...this.filtros, ident: '' };
    this.filtrar();
  }

  /** Días desde que se entregó: para ver si entra en garantía. */
  diasDesde(fecha: string | null): number {
    return fecha ? Math.floor((Date.now() - new Date(fecha).getTime()) / 86400000) : 0;
  }

  nombreUsb(t: TelefonoUsb): string {
    return nombreTelefonoUsb(t) || 'Teléfono';
  }

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

  /** Gasto y ganancia de cada teléfono: solo para el dueño. */
  get verCostos(): boolean {
    return this.auth.tienePermiso('ordenes.ver_costos');
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
        this.cuenta.set(r.cuenta ?? null);
        this.paginaActual.set(r.current_page);
        this.ultimaPagina.set(r.last_page);
        this.cargando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.cargando.set(false);
        this.avisos.fallo(e);
      },
    });
    this.cargarSaldo();
  }

  // ---- saldo a favor del locatario elegido en el filtro: botón para pagar con él

  /** Lo que debe el locatario filtrado en teléfonos entregados: botón para mandarle su saldo por WhatsApp. */
  readonly cuenta = signal<Cuenta | null>(null);

  /** Saldo a favor y plan de pago del locatario que se eligió en el filtro (null si no hay o no tiene). */
  readonly saldoFavor = signal<Elegido | null>(null);

  private cargarSaldo(): void {
    if (!this.filtros.locatario || !this.auth.tienePermiso('saldos.ver')) {
      this.saldoFavor.set(null);
      return;
    }
    this.api.get<{ elegido: Elegido | null }>('saldos', { locatario: this.filtros.locatario }).subscribe({
      next: (r) => this.saldoFavor.set(r.elegido && r.elegido.a_favor > 0 ? r.elegido : null),
      error: () => this.saldoFavor.set(null),
    });
  }

  sumaPlan(e: Elegido): number {
    return e.plan.reduce((s, p) => s + p.monto, 0);
  }

  /** "liquida 2 y abona $140.00 al siguiente", "abona $250.00 al primero" o "liquida 3". */
  resumenPlan(e: Elegido): string {
    const liquidados = e.plan.filter((p) => !p.parcial).length;
    const abono = e.plan.find((p) => p.parcial)?.monto;
    if (!liquidados) return `abona ${dinero(abono ?? 0)} al primero`;
    const liq = `liquida ${liquidados} ${liquidados === 1 ? 'adeudo' : 'adeudos'}`;
    return abono ? `${liq} y abona ${dinero(abono)} al siguiente` : liq;
  }

  /** Paga con el saldo a favor: primero lo entregado que debe, luego lo demás (del más antiguo al más nuevo). */
  async pagarConSaldo(e: Elegido): Promise<void> {
    const texto = `${e.nombre} tiene ${dinero(e.a_favor)} a favor. Se pagará así (primero lo entregado que debe, luego lo demás, del más antiguo al más nuevo):\n\n${textoPlan(e.plan)}\n\n¿Continuar?`;
    if (!await this.dialogo.confirmar(texto)) return;
    this.api.post<{ message: string }>(`saldos/${e.id}/aplicar`).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (er: HttpErrorResponse) => this.avisos.fallo(er),
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

  // ---- "No quedó" pide el motivo: va en el WhatsApp de aviso al locatario
  readonly motivos = MOTIVOS_NO_QUEDO;
  readonly noQuedo = signal<Telefono | null>(null);
  motivoNoQuedo = '';

  marcarNoQuedo(t: Telefono): void {
    // si ya está "No quedó", el botón lo regresa a "En taller" (no pide motivo)
    if (t.resultado === 'NO QUEDÓ') return this.accion(t, 'noquedo');
    this.menu.set(null);
    this.motivoNoQuedo = '';
    this.noQuedo.set(t);
  }

  confirmarNoQuedo(): void {
    const t = this.noQuedo();
    const motivo = this.motivoNoQuedo.trim();
    if (!t || !motivo) return;
    this.noQuedo.set(null);
    this.accion(t, 'noquedo', { motivo });
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

  async entregar(t: Telefono): Promise<void> {
    const pregunta = t.entregado
      ? 'Ya está entregado. ¿Quitarle la entrega?'
      : t.resultado === 'EN TALLER'
        ? 'Sigue En taller (no está Listo ni No quedó). ¿Entregarlo de todos modos?'
        : null;
    this.menu.set(null);
    if (pregunta && !await this.dialogo.confirmar(pregunta)) return;
    this.accion(t, 'entregado');
  }

  abonar(t: Telefono): void {
    if (!this.montoAbono || this.montoAbono <= 0) return;
    this.accion(t, 'abono', { monto: this.montoAbono });
  }

  async liquidar(t: Telefono): Promise<void> {
    this.menu.set(null);
    if (!await this.dialogo.confirmar(`¿Marcar como pagado completo? Se registra un cobro de ${dinero(t.saldo)}.`)) return;
    this.accion(t, 'liquidar');
  }

  exportar(): void {
    this.api.descargar('telefonos/exportar', `telefonos_${fechaISO()}.csv`, this.filtros);
  }
}

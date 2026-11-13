import { HttpErrorResponse } from '@angular/common/http';
import { AfterViewInit, ChangeDetectorRef, Component, ElementRef, OnInit, inject, input, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, fechaISO } from '../../shared/ui';
import type { Telefono } from './telefonos';

const ULTIMO_LOCATARIO = 'taller_ultimo_locatario';

function vacio(locatario = '') {
  return {
    locatario, whatsapp: '', fecha_recibido: fechaISO(), prioridad: 'MEDIA', modelo: '', trabajo: '', notas: '',
    total: 0, pagado: 0, resultado: 'EN TALLER', entregado: false,
  };
}

/** Alta y edición de un teléfono de locatario, en una ventana sobre la lista. */
@Component({
  selector: 'app-telefono-form',
  imports: [FormsModule, DineroPipe, FechaPipe],
  templateUrl: './telefono-form.html',
  host: { '(document:keydown.escape)': 'cerrar.emit()' },
  styles: `
    .modal-telefono { max-width: 760px; }
  `,
})
export class TelefonoForm implements OnInit, AfterViewInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  // la app no usa zone.js: al cambiar `m` desde una respuesta del servidor hay que pedir que se redibuje
  private readonly cdr = inject(ChangeDetectorRef);
  readonly auth = inject(AuthService);

  /** null = alta de un teléfono nuevo. */
  readonly telefonoId = input<number | null>(null);
  /** Abrir directo en la parte de eliminar. */
  readonly irAEliminar = input(false);
  readonly cerrar = output<void>();
  /** Se guardó algo: la lista se tiene que recargar. */
  readonly guardado = output<void>();

  readonly locatarios = signal<{ id: number; nombre: string; whatsapp: string | null }[]>([]);
  readonly guardando = signal(false);
  readonly fechaEntrega = signal<string | null>(null);
  readonly cobros = signal(0);
  readonly errores = signal<string[]>([]);
  private readonly zonaEliminar = viewChild<ElementRef<HTMLElement>>('zonaEliminar');
  private readonly campoLocatario = viewChild<ElementRef<HTMLInputElement>>('campoLocatario');

  m = vacio(sessionStorage.getItem(ULTIMO_LOCATARIO) ?? '');
  destinoCobros = '';

  ngOnInit(): void {
    this.api.get<{ locatarios: { id: number; nombre: string; whatsapp: string | null }[] }>('telefonos', { por_pagina: 1 }).subscribe((r) => {
      this.locatarios.set(r.locatarios);
      if (!this.telefonoId()) this.alCambiarLocatario();
      this.cdr.markForCheck();
    });

    const id = this.telefonoId();
    if (id) {
      this.api.get<Telefono>(`telefonos/${id}`).subscribe({
        next: (t) => {
          this.m = {
            locatario: t.locatario?.nombre ?? '', whatsapp: t.locatario?.whatsapp ?? '', fecha_recibido: t.fecha_recibido.slice(0, 10),
            prioridad: t.prioridad, modelo: t.modelo, trabajo: t.trabajo, notas: t.notas, total: Number(t.total), pagado: Number(t.pagado),
            resultado: t.resultado, entregado: t.entregado,
          };
          this.fechaEntrega.set(t.fecha_entrega);
          this.cobros.set(t.cobros_total ?? 0);
          this.cdr.markForCheck();
          if (this.irAEliminar()) {
            setTimeout(() => this.zonaEliminar()?.nativeElement.scrollIntoView({ behavior: 'smooth' }), 100);
          }
        },
        error: (e: HttpErrorResponse) => {
          this.avisos.fallo(e);
          this.cerrar.emit();
        },
      });
    }
  }

  ngAfterViewInit(): void {
    if (!this.telefonoId()) this.campoLocatario()?.nativeElement.focus();
  }

  /** Al escribir un locatario conocido se llena su WhatsApp. */
  alCambiarLocatario(): void {
    const l = this.locatarios().find((x) => x.nombre.toLowerCase() === this.m.locatario.trim().toLowerCase());
    if (l) this.m.whatsapp = l.whatsapp ?? '';
  }

  guardar(): void {
    this.errores.set([]);
    this.guardando.set(true);
    const id = this.telefonoId();
    const peticion = id
      ? this.api.put<{ message: string; aviso: AvisoWhatsapp | null }>(`telefonos/${id}`, this.m)
      : this.api.post<{ message: string; aviso: AvisoWhatsapp | null }>('telefonos', this.m);

    peticion.subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.avisos.ok(r.message, r.aviso);
        this.guardado.emit();
        if (id) {
          this.cerrar.emit();
        } else {
          // la ventana sigue abierta para capturar varios seguidos del mismo locatario
          sessionStorage.setItem(ULTIMO_LOCATARIO, this.m.locatario);
          const { locatario, whatsapp } = this.m;
          this.m = { ...vacio(locatario), whatsapp };
          this.cdr.markForCheck();
        }
      },
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        const errores = e.error?.errors ? (Object.values(e.error.errors) as string[][]).flat() : [];
        if (errores.length) this.errores.set(errores);
        else this.avisos.fallo(e);
      },
    });
  }

  limpiar(): void {
    if (!confirm('¿Limpiar el formulario?')) return;
    sessionStorage.removeItem(ULTIMO_LOCATARIO);
    this.m = vacio();
    this.errores.set([]);
  }

  eliminar(): void {
    const id = this.telefonoId();
    if (!confirm(`¿Eliminar el teléfono #${id} (${this.m.modelo})? No se puede deshacer.`)) return;
    this.api.delete(`telefonos/${id}`, { cobros: this.destinoCobros }).subscribe({
      next: () => {
        this.avisos.ok(`Teléfono #${id} eliminado.`);
        this.guardado.emit();
        this.cerrar.emit();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

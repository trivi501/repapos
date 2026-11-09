import { HttpErrorResponse } from '@angular/common/http';
import { AfterViewInit, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService, AvisoWhatsapp } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, fechaISO } from '../../shared/ui';
import { Telefono } from './telefonos';

const ULTIMO_LOCATARIO = 'taller_ultimo_locatario';

function vacio(locatario = '') {
  return {
    locatario, whatsapp: '', fecha_recibido: fechaISO(), prioridad: 'MEDIA', modelo: '', trabajo: '', notas: '',
    total: 0, pagado: 0, resultado: 'EN TALLER', entregado: false,
  };
}

@Component({
  selector: 'app-telefono-form',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  templateUrl: './telefono-form.html',
})
export class TelefonoForm implements OnInit, AfterViewInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly id = signal<number | null>(null);
  readonly locatarios = signal<{ id: number; nombre: string; whatsapp: string | null }[]>([]);
  readonly guardando = signal(false);
  readonly fechaEntrega = signal<string | null>(null);
  readonly cobros = signal(0);

  m = vacio(sessionStorage.getItem(ULTIMO_LOCATARIO) ?? '');
  destinoCobros = '';

  ngOnInit(): void {
    this.api.get<{ locatarios: { id: number; nombre: string; whatsapp: string | null }[] }>('telefonos', { por_pagina: 1 }).subscribe((r) => {
      this.locatarios.set(r.locatarios);
      this.alCambiarLocatario();
    });

    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.id.set(id);
      this.api.get<Telefono>(`telefonos/${id}`).subscribe({
        next: (t) => {
          this.m = {
            locatario: t.locatario?.nombre ?? '', whatsapp: t.locatario?.whatsapp ?? '', fecha_recibido: t.fecha_recibido.slice(0, 10),
            prioridad: t.prioridad, modelo: t.modelo, trabajo: t.trabajo, notas: t.notas, total: Number(t.total), pagado: Number(t.pagado),
            resultado: t.resultado, entregado: t.entregado,
          };
          this.fechaEntrega.set(t.fecha_entrega);
          this.cobros.set(t.cobros_total ?? 0);
        },
        error: (e: HttpErrorResponse) => this.avisos.fallo(e),
      });
    }
  }

  ngAfterViewInit(): void {
    if (this.route.snapshot.fragment === 'eliminar') {
      setTimeout(() => document.getElementById('eliminar')?.scrollIntoView({ behavior: 'smooth' }), 400);
    }
  }

  /** Al escribir un locatario conocido se llena su WhatsApp. */
  alCambiarLocatario(): void {
    const l = this.locatarios().find((x) => x.nombre.toLowerCase() === this.m.locatario.trim().toLowerCase());
    if (l) this.m.whatsapp = l.whatsapp ?? '';
  }

  guardar(): void {
    this.guardando.set(true);
    const id = this.id();
    const peticion = id
      ? this.api.put<{ message: string; aviso: AvisoWhatsapp | null }>(`telefonos/${id}`, this.m)
      : this.api.post<{ message: string; aviso: AvisoWhatsapp | null }>('telefonos', this.m);

    peticion.subscribe({
      next: (r) => {
        this.guardando.set(false);
        if (id) {
          this.router.navigate(['/telefonos']).then(() => this.avisos.ok(r.message, r.aviso));
        } else {
          // se queda en la pantalla para capturar varios seguidos del mismo locatario
          sessionStorage.setItem(ULTIMO_LOCATARIO, this.m.locatario);
          const locatario = this.m.locatario;
          const whatsapp = this.m.whatsapp;
          this.m = { ...vacio(locatario), whatsapp };
          this.avisos.ok(r.message, r.aviso);
        }
      },
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  limpiar(): void {
    if (!confirm('¿Limpiar el formulario?')) return;
    sessionStorage.removeItem(ULTIMO_LOCATARIO);
    this.m = vacio();
  }

  eliminar(): void {
    if (!confirm(`¿Eliminar el teléfono #${this.id()} (${this.m.modelo})? No se puede deshacer.`)) return;
    this.api.delete(`telefonos/${this.id()}`, { cobros: this.destinoCobros }).subscribe({
      next: () => this.router.navigate(['/telefonos']).then(() => this.avisos.ok(`Teléfono #${this.id()} eliminado.`)),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

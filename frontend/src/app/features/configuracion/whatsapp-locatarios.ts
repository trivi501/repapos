import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';

interface LocatarioWhatsapp {
  id: number;
  nombre: string;
  whatsapp: string | null;
}

/** Ajustes → WhatsApp de locatarios: el número al que se mandan sus avisos y su cuenta. */
@Component({
  selector: 'app-whatsapp-locatarios',
  imports: [FormsModule],
  template: `
    <div class="encabezado"><h1>WhatsApp de locatarios</h1></div>

    <div class="card tabla-scroll">
      <div class="titulo-tarjeta">
        <h2>{{ locatarios().length }} locatario(s) · {{ sinNumero() }} sin número</h2>
        <input class="search-input" type="search" placeholder="Buscar locatario…" aria-label="Buscar locatario"
               [ngModel]="filtro()" (ngModelChange)="filtro.set($event)" />
      </div>
      <form (ngSubmit)="guardar()">
        <table>
          <thead><tr><th>Locatario</th><th>WhatsApp</th></tr></thead>
          <tbody>
            @for (l of visibles(); track l.id) {
              <tr>
                <td>{{ l.nombre }}</td>
                <td>
                  <input inputmode="tel" placeholder="10 dígitos" [name]="'wa' + l.id" [attr.aria-label]="'WhatsApp de ' + l.nombre"
                         [disabled]="!puedeEditar" [(ngModel)]="l.whatsapp" />
                </td>
              </tr>
            } @empty {
              <tr><td colspan="2"><div class="empty-state">{{ cargado() ? (filtro() ? 'Ningún locatario con ese nombre.' : 'Todavía no hay locatarios.') : 'Cargando…' }}</div></td></tr>
            }
          </tbody>
        </table>
        @if (puedeEditar && locatarios().length) {
          <div class="contenido"><button class="btn btn-primary" type="submit" [disabled]="guardando()">Guardar</button></div>
        }
      </form>
    </div>
    <p class="ayuda">A este número se mandan los avisos de LISTO / NO QUEDÓ y la cuenta de cada locatario.</p>
  `,
})
export class WhatsAppLocatarios implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly auth = inject(AuthService);

  readonly puedeEditar = this.auth.tienePermiso('configuracion.editar');
  readonly locatarios = signal<LocatarioWhatsapp[]>([]);
  readonly cargado = signal(false);
  readonly guardando = signal(false);
  readonly filtro = signal('');

  readonly visibles = computed(() => {
    const f = this.filtro().trim().toLowerCase();
    return f ? this.locatarios().filter((l) => l.nombre.toLowerCase().includes(f)) : this.locatarios();
  });
  readonly sinNumero = computed(() => this.locatarios().filter((l) => !l.whatsapp).length);

  ngOnInit(): void {
    this.cargar();
  }

  private cargar(): void {
    this.api.get<LocatarioWhatsapp[]>('whatsapp/locatarios').subscribe({
      next: (l) => {
        this.locatarios.set(l);
        this.cargado.set(true);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  guardar(): void {
    const whatsapp: Record<number, string> = {};
    for (const l of this.locatarios()) whatsapp[l.id] = l.whatsapp ?? '';
    this.guardando.set(true);
    this.api.put<{ message: string }>('whatsapp/locatarios', { whatsapp }).subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.avisos.fallo(e);
      },
    });
  }
}

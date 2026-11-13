import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { FechaPipe } from '../../shared/ui';

interface Mensaje {
  id: number;
  telefono: string;
  tipo: string;
  texto: string;
  estado: string;
  error: string | null;
  created_at: string;
  user?: { name: string } | null;
}

interface Respuesta {
  data: Mensaje[];
  current_page: number;
  last_page: number;
  conteo: { total: number; enviados: number; fallidos: number };
  activa: boolean;
}

/** Historial de los mensajes de WhatsApp que mandó el sistema (envío directo). */
@Component({
  selector: 'app-whatsapp-mensajes',
  imports: [FormsModule, RouterLink, FechaPipe],
  styles: `
    .texto-mensaje { white-space: pre-line; max-width: 46ch; }
    .texto-mensaje.corto { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .ver-mas { background: none; border: 0; padding: 0; color: var(--color-primary); cursor: pointer; font-size: 0.8rem; }
  `,
  template: `
    <div class="encabezado">
      <h1>Mensajes de WhatsApp</h1>
      @if (auth.tienePermiso('configuracion.editar')) {
        <a class="btn btn-secondary" routerLink="/configuracion">⚙️ Configurar WhatsApp</a>
      }
    </div>

    @if (r(); as x) {
      @if (!x.activa) {
        <p class="alert alert-error">
          El envío directo está apagado: los botones abren WhatsApp y esos mensajes no quedan aquí. Actívalo en Configuración → WhatsApp.
        </p>
      }
      <div class="barra-resumen">
        <span>Mensajes: {{ x.conteo.total }}</span>
        <span class="a-favor">Enviados: {{ x.conteo.enviados }}</span>
        <span [class.resaltado]="x.conteo.fallidos > 0">Fallidos: {{ x.conteo.fallidos }}</span>
      </div>
    }

    <div class="card filtros">
      <div class="form-field">
        <label for="m_estado">Estado</label>
        <select id="m_estado" [(ngModel)]="filtros.estado" (ngModelChange)="filtrar()">
          <option value="">Todos</option>
          <option value="enviado">Enviados</option>
          <option value="fallido">Fallidos</option>
        </select>
      </div>
      <div class="form-field">
        <label for="m_desde">Desde</label>
        <input id="m_desde" type="date" [(ngModel)]="filtros.desde" (ngModelChange)="filtrar()" />
      </div>
      <div class="form-field">
        <label for="m_hasta">Hasta</label>
        <input id="m_hasta" type="date" [(ngModel)]="filtros.hasta" (ngModelChange)="filtrar()" />
      </div>
      <div class="form-field">
        <label for="m_q">Buscar</label>
        <input id="m_q" type="search" placeholder="Número o texto del mensaje" [(ngModel)]="filtros.q" (keyup.enter)="filtrar()" />
      </div>
      <button class="btn btn-secondary" (click)="filtrar()">Filtrar</button>
    </div>

    <div class="card tabla-scroll">
      <table>
        <thead><tr><th>Cuándo</th><th>A</th><th>Mensaje</th><th>Estado</th><th>Envió</th><th></th></tr></thead>
        <tbody>
          @for (m of mensajes(); track m.id) {
            <tr>
              <td class="sin-salto">{{ m.created_at | fecha: true }}</td>
              <td class="sin-salto">{{ m.telefono }}</td>
              <td>
                <div class="texto-mensaje" [class.corto]="!abiertos().has(m.id)">{{ m.texto }}</div>
                @if (esLargo(m)) {
                  <button class="ver-mas" type="button" (click)="alternar(m.id)">{{ abiertos().has(m.id) ? 'Ver menos' : 'Ver todo' }}</button>
                }
              </td>
              <td>
                <span class="badge" [class.badge-success]="m.estado === 'enviado'" [class.badge-danger]="m.estado !== 'enviado'">{{ m.estado }}</span>
                @if (m.error) {
                  <small class="bloque-texto debe">{{ m.error }}</small>
                }
              </td>
              <td class="tenue">{{ m.user?.name }}</td>
              <td class="sin-salto">
                @if (m.estado !== 'enviado' && r()?.activa) {
                  <button class="btn btn-secondary btn-sm" (click)="reenviar(m)" [disabled]="reenviando() === m.id">Reenviar</button>
                }
              </td>
            </tr>
          } @empty {
            <tr><td colspan="6"><div class="empty-state">{{ cargando() ? 'Cargando…' : 'No hay mensajes con estos filtros.' }}</div></td></tr>
          }
        </tbody>
      </table>
      <div class="pagination">
        <button class="btn btn-secondary" (click)="pagina(-1)" [disabled]="paginaActual() <= 1">Anterior</button>
        <span>Página {{ paginaActual() }} de {{ ultimaPagina() }}</span>
        <button class="btn btn-secondary" (click)="pagina(1)" [disabled]="paginaActual() >= ultimaPagina()">Siguiente</button>
      </div>
    </div>
  `,
})
export class WhatsAppMensajes implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly r = signal<Respuesta | null>(null);
  readonly mensajes = signal<Mensaje[]>([]);
  readonly cargando = signal(true);
  readonly paginaActual = signal(1);
  readonly ultimaPagina = signal(1);
  readonly abiertos = signal(new Set<number>());
  readonly reenviando = signal<number | null>(null);

  filtros = { estado: '', desde: '', hasta: '', q: '' };

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.api.get<Respuesta>('whatsapp/mensajes', { ...this.filtros, page: this.paginaActual() }).subscribe({
      next: (r) => {
        this.r.set(r);
        this.mensajes.set(r.data);
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

  /** Más de dos renglones: se muestra recortado con "Ver todo". */
  esLargo(m: Mensaje): boolean {
    return m.texto.length > 90 || m.texto.split('\n').length > 2;
  }

  alternar(id: number): void {
    this.abiertos.update((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  }

  reenviar(m: Mensaje): void {
    this.reenviando.set(m.id);
    this.api.post<{ message: string }>(`whatsapp/mensajes/${m.id}/reenviar`, {}).subscribe({
      next: (r) => {
        this.reenviando.set(null);
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.reenviando.set(null);
        this.avisos.fallo(e);
        this.cargar();
      },
    });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { FechaPipe } from '../../shared/ui';

interface Config {
  activa: boolean;
  encendida: boolean;
  tiene_token: boolean;
  token_final: string | null;
  token_ilegible: boolean;
  numero_id: string;
  plantilla: string;
  idioma: string;
  dueno: string;
  mensajes: { id: number; telefono: string; tipo: string; texto: string; estado: string; error: string | null; created_at: string; user?: { name: string } | null }[];
}

/** Conexión con la API de WhatsApp Cloud (Meta): con ella los botones de WhatsApp envían directo. */
@Component({
  selector: 'app-whatsapp-api',
  imports: [FormsModule, FechaPipe],
  template: `
    @if (c(); as x) {
      <form class="card contenido separado" (ngSubmit)="guardar()">
        <div class="titulo-tarjeta">
          <h2>WhatsApp Business (API de Meta)</h2>
          <span class="badge" [class.badge-success]="x.activa" [class.badge-warning]="!x.activa">{{ x.activa ? 'Conectado: envía directo' : 'Apagado: abre WhatsApp' }}</span>
        </div>
        <p class="ayuda">
          Con la API activa, los botones de WhatsApp (avisar que está listo, ticket, estado de cuenta, corte…) mandan el mensaje
          directo, sin abrir WhatsApp. Los datos salen de Meta → tu app → WhatsApp → Configuración de la API.
        </p>
        @if (x.token_ilegible) {
          <p class="alert alert-error">El token guardado ya no se puede leer (cambió la clave del sistema). Captúralo otra vez.</p>
        }

        <div class="rejilla-2">
          <div class="form-field">
            <label for="wa_numero_id">ID del número de teléfono *</label>
            <input id="wa_numero_id" name="numero_id" inputmode="numeric" autocomplete="off" placeholder="Ej. 109876543210987" [(ngModel)]="f.numero_id" />
            <span class="ayuda">No es el teléfono: es el "Phone number ID" que muestra Meta.</span>
          </div>
          <div class="form-field">
            <label for="wa_token">Token de acceso {{ x.tiene_token ? '(guardado: …' + x.token_final + ')' : '*' }}</label>
            <input id="wa_token" name="token" type="password" autocomplete="new-password" spellcheck="false"
                   [placeholder]="x.tiene_token ? 'Déjalo vacío para conservar el actual' : 'Pega aquí el token permanente'" [(ngModel)]="f.token" />
            <span class="ayuda">Usa un token permanente de un usuario del sistema (el temporal vence en 24 horas). Se guarda cifrado y no se vuelve a mostrar.</span>
          </div>
          <div class="form-field">
            <label for="wa_plantilla">Nombre de la plantilla aprobada</label>
            <input id="wa_plantilla" name="plantilla" autocomplete="off" placeholder="Ej. aviso_taller" [(ngModel)]="f.plantilla" />
            <span class="ayuda">
              Su cuerpo debe tener la variable <code>{{ '{' }}{{ '{' }}1{{ '}' }}{{ '}' }}</code>: ahí va el mensaje. Sin plantilla solo llega a quien te escribió en las últimas 24 horas.
            </span>
          </div>
          <div class="form-field campo-corto">
            <label for="wa_idioma">Idioma de la plantilla</label>
            <input id="wa_idioma" name="idioma" placeholder="es_MX" [(ngModel)]="f.idioma" />
          </div>
          <div class="form-field">
            <label for="wa_dueno">WhatsApp del dueño</label>
            <input id="wa_dueno" name="dueno" inputmode="tel" placeholder="10 dígitos" [(ngModel)]="f.dueno" />
            <span class="ayuda">Recibe lo que no tiene destinatario, como el corte de caja.</span>
          </div>
        </div>

        <label class="check-linea">
          <input type="checkbox" name="encendida" [(ngModel)]="f.encendida" />
          Activar: los botones de WhatsApp envían directo por la API
        </label>
        @if (x.tiene_token) {
          <label class="check-linea"><input type="checkbox" name="quitar_token" [(ngModel)]="f.quitar_token" /> Borrar el token guardado</label>
        }

        @if (puedeEditar) {
          <div class="acciones-fila separado">
            <button class="btn btn-primary" type="submit" [disabled]="guardando()">Guardar</button>
            <button class="btn btn-secondary" type="button" (click)="probar()" [disabled]="!x.tiene_token || guardando()">Probar conexión</button>
          </div>
        }

        @if (x.mensajes.length) {
          <h3 class="separado">Últimos mensajes enviados</h3>
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Cuándo</th><th>A</th><th>Mensaje</th><th>Estado</th><th>Quién</th></tr></thead>
              <tbody>
                @for (m of x.mensajes; track m.id) {
                  <tr>
                    <td class="sin-salto">{{ m.created_at | fecha: true }}</td>
                    <td class="sin-salto">{{ m.telefono }}</td>
                    <td class="tenue" [title]="m.texto">{{ m.texto.length > 70 ? m.texto.slice(0, 70) + '…' : m.texto }}</td>
                    <td>
                      <span class="badge" [class.badge-success]="m.estado === 'enviado'" [class.badge-danger]="m.estado !== 'enviado'">{{ m.estado }}</span>
                      @if (m.error) {
                        <small class="bloque-texto debe">{{ m.error }}</small>
                      }
                    </td>
                    <td class="tenue">{{ m.user?.name }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </form>
    }
  `,
})
export class WhatsAppApi implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly auth = inject(AuthService);

  readonly c = signal<Config | null>(null);
  readonly guardando = signal(false);
  readonly puedeEditar = this.auth.tienePermiso('configuracion.editar');

  f = { numero_id: '', token: '', plantilla: '', idioma: 'es_MX', dueno: '', encendida: false, quitar_token: false };

  ngOnInit(): void {
    this.cargar();
  }

  private cargar(): void {
    this.api.get<Config>('whatsapp-api').subscribe({
      next: (c) => {
        this.f = {
          numero_id: c.numero_id, token: '', plantilla: c.plantilla, idioma: c.idioma || 'es_MX', dueno: c.dueno,
          encendida: c.encendida, quitar_token: false,
        };
        this.c.set(c);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  guardar(): void {
    this.guardando.set(true);
    this.api.put<{ message: string }>('whatsapp-api', this.f).subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.avisos.ok(r.message);
        this.auth.refrescar().subscribe();   // los botones de WhatsApp cambian según esté activa
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.avisos.fallo(e);
        this.auth.refrescar().subscribe();
        this.cargar();
      },
    });
  }

  probar(): void {
    this.guardando.set(true);
    this.api.post<{ message: string }>('whatsapp-api/probar', {}).subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.avisos.ok(r.message);
      },
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.avisos.fallo(e);
      },
    });
  }
}

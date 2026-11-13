import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { RouterLink } from '@angular/router';

type Proveedor = 'evolution' | 'meta';

interface Config {
  proveedor: Proveedor;
  activa: boolean;
  encendida: boolean;
  dueno: string;
  evolution: { disponible: boolean; instancia: string | null };
  tiene_token: boolean;
  token_final: string | null;
  token_ilegible: boolean;
  numero_id: string;
  plantilla: string;
  idioma: string;
}

const TEXTO_ESTADO: Record<string, string> = {
  open: 'Vinculado',
  connecting: 'Esperando que escanees el QR',
  close: 'Desvinculado',
  no_existe: 'Sin vincular',
};

/** Envío directo de WhatsApp: Evolution API (QR, open source) o la API oficial de Meta. */
@Component({
  selector: 'app-whatsapp-api',
  imports: [FormsModule, RouterLink],
  styles: `
    .proveedores { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.75rem; margin: 0.75rem 0 1rem; }
    .proveedor { border: 1px solid var(--color-border); border-radius: var(--radius); padding: 0.75rem; cursor: pointer; display: block; }
    .proveedor.elegido { border-color: var(--color-primary); box-shadow: 0 0 0 2px var(--color-focus-ring); }
    .proveedor small { display: block; margin-top: 0.25rem; }
    .qr { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin: 0.75rem 0; }
    .qr img { width: 240px; height: 240px; background: #fff; padding: 8px; border-radius: 8px; }
  `,
  template: `
    @if (c(); as x) {
      <form class="card contenido separado" (ngSubmit)="guardar()">
        <div class="titulo-tarjeta">
          <h2>WhatsApp: envío directo</h2>
          <span class="badge" [class.badge-success]="x.activa" [class.badge-warning]="!x.activa">{{ x.activa ? 'Activo: envía directo' : 'Apagado: abre WhatsApp' }}</span>
        </div>
        <p class="ayuda">
          Con el envío directo activo, los botones de WhatsApp (avisar que está listo, ticket, estado de cuenta, corte…) mandan el
          mensaje desde el sistema, sin abrir WhatsApp.
        </p>

        <div class="proveedores" role="radiogroup" aria-label="Proveedor de WhatsApp">
          <label class="proveedor" [class.elegido]="f.proveedor === 'evolution'">
            <input type="radio" name="proveedor" value="evolution" [(ngModel)]="f.proveedor" />
            <b>Evolution API (código QR)</b>
            <small class="tenue">Gratis y open source. Vinculas tu WhatsApp escaneando un QR, como WhatsApp Web. No es oficial: usa un número aparte y no mandes mensajes masivos.</small>
          </label>
          <label class="proveedor" [class.elegido]="f.proveedor === 'meta'">
            <input type="radio" name="proveedor" value="meta" [(ngModel)]="f.proveedor" />
            <b>Meta (API oficial)</b>
            <small class="tenue">Requiere cuenta de Meta Business, token y plantilla aprobada. Meta cobra por conversación.</small>
          </label>
        </div>

        @if (f.proveedor === 'evolution') {
          @if (!x.evolution.disponible) {
            <p class="alert alert-error">
              El servidor todavía no tiene Evolution API instalada. Hay que instalarla en el VPS siguiendo la guía
              <code>deploy/EVOLUTION-API.md</code> y poner EVOLUTION_URL y EVOLUTION_API_KEY en el .env.
            </p>
          } @else {
            <div class="form-field">
              <span class="etiqueta-campo">WhatsApp del taller</span>
              <div>
                <span class="badge" [class.badge-success]="estado() === 'open'" [class.badge-warning]="estado() !== 'open'">{{ textoEstado() }}</span>
                <small class="tenue"> · instancia {{ x.evolution.instancia }}</small>
              </div>
            </div>
            @if (qr(); as imagen) {
              <div class="qr">
                <img [src]="imagen" alt="Código QR para vincular WhatsApp" />
                <ol class="ayuda">
                  <li>Abre WhatsApp en el celular del taller.</li>
                  <li>Menú ⋮ (o Configuración) → <b>Dispositivos vinculados</b> → <b>Vincular un dispositivo</b>.</li>
                  <li>Escanea este código. Se actualiza solo cuando se conecte.</li>
                </ol>
              </div>
            }
            @if (puedeEditar) {
              <div class="acciones-fila">
                @if (estado() !== 'open') {
                  <button class="btn btn-secondary" type="button" (click)="conectar()" [disabled]="trabajando()">{{ qr() ? 'Nuevo código QR' : 'Vincular con código QR' }}</button>
                } @else {
                  <button class="btn btn-danger" type="button" (click)="desconectar()" [disabled]="trabajando()">Desvincular WhatsApp</button>
                }
                <button class="btn btn-secondary" type="button" (click)="revisarEstado()" [disabled]="trabajando()">Revisar estado</button>
              </div>
            }
          }
        } @else {
          @if (x.token_ilegible) {
            <p class="alert alert-error">El token guardado ya no se puede leer (cambió la clave del sistema). Captúralo otra vez.</p>
          }
          <div class="rejilla-2">
            <div class="form-field">
              <label for="wa_numero_id">ID del número de teléfono *</label>
              <input id="wa_numero_id" name="numero_id" inputmode="numeric" autocomplete="off" placeholder="Ej. 109876543210987" [(ngModel)]="f.numero_id" />
              <span class="ayuda">No es el teléfono: es el "Phone number ID" de Meta → WhatsApp → Configuración de la API.</span>
            </div>
            <div class="form-field">
              <label for="wa_token">Token de acceso {{ x.tiene_token ? '(guardado: …' + x.token_final + ')' : '*' }}</label>
              <input id="wa_token" name="token" type="password" autocomplete="new-password" spellcheck="false"
                     [placeholder]="x.tiene_token ? 'Déjalo vacío para conservar el actual' : 'Pega aquí el token permanente'" [(ngModel)]="f.token" />
              <span class="ayuda">Token permanente de un usuario del sistema. Se guarda cifrado y no se vuelve a mostrar.</span>
            </div>
            <div class="form-field">
              <label for="wa_plantilla">Nombre de la plantilla aprobada</label>
              <input id="wa_plantilla" name="plantilla" autocomplete="off" placeholder="Ej. aviso_taller" [(ngModel)]="f.plantilla" />
              <span class="ayuda">Su cuerpo debe tener la variable <code>{{ '{' }}{{ '{' }}1{{ '}' }}{{ '}' }}</code>.</span>
            </div>
            <div class="form-field campo-corto">
              <label for="wa_idioma">Idioma de la plantilla</label>
              <input id="wa_idioma" name="idioma" placeholder="es_MX" [(ngModel)]="f.idioma" />
            </div>
          </div>
          @if (x.tiene_token) {
            <label class="check-linea"><input type="checkbox" name="quitar_token" [(ngModel)]="f.quitar_token" /> Borrar el token guardado</label>
          }
        }

        <div class="form-field separado">
          <label for="wa_dueno">WhatsApp del dueño</label>
          <input id="wa_dueno" name="dueno" inputmode="tel" placeholder="10 dígitos" [(ngModel)]="f.dueno" />
          <span class="ayuda">Recibe lo que no tiene destinatario, como el corte de caja.</span>
        </div>

        <label class="check-linea">
          <input type="checkbox" name="encendida" [(ngModel)]="f.encendida" />
          Activar: los botones de WhatsApp envían directo
        </label>

        @if (puedeEditar) {
          <div class="acciones-fila separado">
            <button class="btn btn-primary" type="submit" [disabled]="trabajando()">Guardar</button>
            @if (f.proveedor === 'meta') {
              <button class="btn btn-secondary" type="button" (click)="probarMeta()" [disabled]="!x.tiene_token || trabajando()">Probar conexión</button>
            }
          </div>
        }

        <p class="separado"><a routerLink="/whatsapp/mensajes">💬 Ver los mensajes enviados →</a></p>
      </form>
    }
  `,
})
export class WhatsAppApi implements OnInit, OnDestroy {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly auth = inject(AuthService);

  readonly c = signal<Config | null>(null);
  readonly trabajando = signal(false);
  readonly estado = signal<string>('no_existe');
  readonly qr = signal<string | null>(null);
  readonly puedeEditar = this.auth.tienePermiso('configuracion.editar');

  f = { proveedor: 'evolution' as Proveedor, numero_id: '', token: '', plantilla: '', idioma: 'es_MX', dueno: '', encendida: false, quitar_token: false };
  private sondeo: ReturnType<typeof setInterval> | null = null;

  ngOnInit(): void {
    this.cargar();
  }

  ngOnDestroy(): void {
    this.pararSondeo();
  }

  textoEstado(): string {
    return TEXTO_ESTADO[this.estado()] ?? this.estado();
  }

  private cargar(): void {
    this.api.get<Config>('whatsapp-api').subscribe({
      next: (c) => {
        this.f = {
          // sin nada configurado se propone Evolution (gratis); si ya usaban Meta, se queda Meta
          proveedor: c.proveedor === 'meta' && !c.tiene_token && !c.encendida ? 'evolution' : c.proveedor,
          numero_id: c.numero_id, token: '', plantilla: c.plantilla, idioma: c.idioma || 'es_MX', dueno: c.dueno,
          encendida: c.encendida, quitar_token: false,
        };
        this.c.set(c);
        if (c.evolution.disponible) this.revisarEstado(false);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  guardar(): void {
    this.trabajando.set(true);
    this.api.put<{ message: string }>('whatsapp-api', this.f).subscribe({
      next: (r) => {
        this.trabajando.set(false);
        this.avisos.ok(r.message);
        this.auth.refrescar().subscribe();   // los botones de WhatsApp cambian según esté activo
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.trabajando.set(false);
        this.avisos.fallo(e);
        this.auth.refrescar().subscribe();
        this.cargar();
      },
    });
  }

  probarMeta(): void {
    this.trabajando.set(true);
    this.api.post<{ message: string }>('whatsapp-api/probar', {}).subscribe({
      next: (r) => {
        this.trabajando.set(false);
        this.avisos.ok(r.message);
      },
      error: (e: HttpErrorResponse) => {
        this.trabajando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  // ---------------------------------------------------------------- Evolution (QR)

  revisarEstado(avisar = true): void {
    this.api.get<{ estado: string }>('whatsapp-api/evolution/estado').subscribe({
      next: (r) => {
        this.estado.set(r.estado);
        if (r.estado === 'open') {
          if (this.qr()) this.avisos.ok('¡WhatsApp vinculado! Marca "Activar" y guarda para que los botones envíen directo.');
          this.qr.set(null);
          this.pararSondeo();
        }
      },
      error: (e: HttpErrorResponse) => {
        if (avisar) this.avisos.fallo(e);
      },
    });
  }

  conectar(): void {
    this.trabajando.set(true);
    this.api.post<{ estado: string; qr: string | null }>('whatsapp-api/evolution/conectar', {}).subscribe({
      next: (r) => {
        this.trabajando.set(false);
        this.estado.set(r.estado);
        this.qr.set(r.qr);
        if (r.qr) this.empezarSondeo();
      },
      error: (e: HttpErrorResponse) => {
        this.trabajando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  desconectar(): void {
    if (!confirm('¿Desvincular el WhatsApp del taller? Los botones volverán a abrir WhatsApp.')) return;
    this.trabajando.set(true);
    this.api.post<{ message: string }>('whatsapp-api/evolution/desconectar', {}).subscribe({
      next: (r) => {
        this.trabajando.set(false);
        this.avisos.ok(r.message);
        this.auth.refrescar().subscribe();
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.trabajando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  /** Mientras se ve el QR, pregunta cada 3 segundos si ya se vinculó (máximo 3 minutos). */
  private empezarSondeo(): void {
    this.pararSondeo();
    const fin = Date.now() + 3 * 60_000;
    this.sondeo = setInterval(() => {
      if (Date.now() > fin) {
        this.pararSondeo();
        this.qr.set(null);
        return;
      }
      this.revisarEstado(false);
    }, 3000);
  }

  private pararSondeo(): void {
    if (this.sondeo) clearInterval(this.sondeo);
    this.sondeo = null;
  }
}

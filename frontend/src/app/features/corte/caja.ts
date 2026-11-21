import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, fechaISO } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';
import { CorteDatos, esperado, hora, textoDiferencia } from './corte-datos';

/**
 * Abrir y cerrar la caja (permiso cortes.guardar).
 * - Cerrada: se cuenta el fondo con que empieza y se abre.
 * - Abierta: se ve lo que debe haber, se cuenta el efectivo y se cierra (se guarda el corte del turno).
 */
@Component({
  selector: 'app-caja',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  template: `
    <div class="encabezado">
      <h1>Abrir / cerrar caja</h1>
      <div class="acciones-fila"><a class="btn btn-secondary" routerLink="/corte">Ver caja</a></div>
    </div>

    @if (d(); as x) {
      @if (cerrado(); as c) {
        <div class="card contenido separado-abajo aviso-cierre">
          <h2>✔ Caja cerrada · turno {{ c.turno }}</h2>
          <p>Contado {{ c.contado | dinero }} · {{ textoDiferencia(c.diferencia) }} · se retiró {{ c.retiro | dinero }} · quedó de fondo {{ c.fondo_siguiente | dinero }}.</p>
          <a class="btn btn-secondary btn-sm" routerLink="/corte" [queryParams]="{ fecha: c.fecha, turno: c.turno }">Ver el corte completo</a>
        </div>
      }

      @if (!x.apertura) {
        <!-- abrir -->
        <div class="card contenido caja-paso">
          <h2><span class="punto cerrada"></span> La caja está cerrada</h2>
          <p class="ayuda">Cuenta el efectivo que hay en la caja antes de empezar. El último corte dejó {{ x.fondo_sugerido | dinero }}.</p>
          <div class="lista-corte">
            <label for="fondo">Fondo inicial contado:</label>
            <input id="fondo" class="input-corto" type="number" step="0.01" min="0" [(ngModel)]="fondo" (focus)="$any($event.target).select()" />
            <label for="notas_ap">Notas:</label>
            <input id="notas_ap" maxlength="255" placeholder="Opcional (p. ej. turno de la mañana)" [(ngModel)]="notas" />
          </div>
          @if (fondo !== x.fondo_sugerido) {
            <p class="ayuda aviso-naranja">El fondo no coincide con lo que dejó el último corte ({{ x.fondo_sugerido | dinero }}).</p>
          }
          <button class="btn btn-primary separado" (click)="abrir()" [disabled]="enviando() || !(fondo >= 0)">Abrir caja</button>
        </div>
      } @else {
        <!-- cerrar -->
        @let a = x.apertura;
        <div class="card contenido caja-paso">
          <h2><span class="punto abierta"></span> Caja abierta</h2>
          <p>
            Desde las <strong>{{ hora(a.abierta_at) }}</strong>{{ a.fecha.slice(0, 10) !== hoy ? ' del ' + (a.fecha | fecha) : '' }}
            {{ a.user ? 'por ' + a.user.name : '' }}{{ a.notas ? ' · ' + a.notas : '' }}
          </p>
          <div class="rejilla-2">
            <div class="lista-corte">
              <span>Fondo inicial:</span>
              <b class="grande">{{ a.fondo | dinero }}</b>
              <span>+ Entradas en efectivo:</span>
              <span>
                <b class="a-favor grande">{{ x.entradas | dinero }}</b> <small class="tenue">({{ x.cobros_efectivo }} cobros)</small>
                @for (o of otros(x); track o.metodo) {
                  <small class="bloque-texto">{{ o.texto }}: {{ o.monto | dinero }} (no está en la caja)</small>
                }
              </span>
              <span>− Salidas:</span>
              <span><b class="debe grande">{{ x.total_salidas | dinero }}</b> <small class="tenue">({{ x.salidas.length }})</small></span>
              <b>= Debe haber en caja:</b>
              <b class="muy-grande">{{ debeHaber() | dinero }}</b>
            </div>
            <div class="lista-corte">
              <label for="contado">Efectivo contado:</label>
              <input id="contado" class="input-corto" type="number" step="0.01" min="0" [(ngModel)]="contado" (ngModelChange)="alContar()" (focus)="$any($event.target).select()" />
              <span>Diferencia:</span>
              @if (contado != null) {
                <b class="muy-grande" [class.a-favor]="diferencia() === 0" [class.debe]="diferencia() < 0" [class.sobra]="diferencia() > 0">{{ textoDiferencia(diferencia()) }}</b>
              } @else {
                <span class="tenue">Escribe lo que contaste</span>
              }
              <label for="retiro">Se retira:</label>
              <span>
                <input id="retiro" class="input-corto" type="number" step="0.01" min="0" [(ngModel)]="retiro" (ngModelChange)="retiroEscrito = true" (focus)="$any($event.target).select()" />
                <small class="bloque-texto tenue">Lo que se lleva el dueño o se deposita.</small>
              </span>
              <span>Queda de fondo:</span>
              <b class="grande" [class.debe]="quedaDeFondo() < 0">{{ quedaDeFondo() | dinero }}</b>
              <label for="notas_ci">Notas:</label>
              <input id="notas_ci" maxlength="255" [(ngModel)]="notas" />
            </div>
          </div>
          <button class="btn btn-primary separado" (click)="cerrar()" [disabled]="enviando() || contado == null || retiro > contado">Cerrar caja</button>
        </div>
      }
    }
  `,
  styles: `
    .caja-paso h2 { display: flex; align-items: center; gap: 0.5rem; }
    .punto { width: 0.8rem; height: 0.8rem; border-radius: 50%; display: inline-block; }
    .punto.abierta { background: #16a34a; }
    .punto.cerrada { background: #9ca3af; }
    .aviso-cierre { border: 1px solid #16a34a; }
  `,
})
export class Caja implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);

  readonly hoy = fechaISO();
  readonly hora = hora;
  readonly textoDiferencia = textoDiferencia;
  readonly d = signal<CorteDatos | null>(null);
  readonly enviando = signal(false);
  /** El corte que se acaba de guardar al cerrar la caja. */
  readonly cerrado = signal<{ turno: number; fecha: string; contado: number; diferencia: number; retiro: number; fondo_siguiente: number } | null>(null);

  fondo = 0;
  contado: number | null = null;
  retiro = 0;
  retiroEscrito = false;
  notas = '';

  ngOnInit(): void {
    this.cargar(this.hoy);
  }

  /** El turno abierto. Si la caja se abrió otro día (se quedó abierta), se ve el turno de ese día. */
  private cargar(fecha: string): void {
    this.api.get<CorteDatos>('cortes', { fecha }).subscribe({
      next: (d) => {
        if (d.apertura && d.apertura.fecha.slice(0, 10) !== fecha) return this.cargar(d.apertura.fecha.slice(0, 10));
        this.fondo = d.fondo_sugerido;
        this.contado = null;
        this.retiro = 0;
        this.retiroEscrito = false;
        this.notas = '';
        this.d.set(d);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  otros(x: CorteDatos) {
    return Object.entries(x.otros_metodos).map(([metodo, monto]) => ({ metodo, monto, texto: metodoTexto(metodo) }));
  }

  debeHaber(): number {
    const x = this.d();
    return x?.apertura ? esperado(x, Number(x.apertura.fondo)) : 0;
  }

  diferencia(): number {
    return Math.round((Number(this.contado ?? 0) - this.debeHaber()) * 100) / 100;
  }

  /** Por defecto se deja el mismo fondo con que se abrió y se retira lo demás. */
  alContar(): void {
    const fondo = Number(this.d()?.apertura?.fondo ?? 0);
    if (!this.retiroEscrito) this.retiro = Math.max(0, Math.round((Number(this.contado ?? 0) - fondo) * 100) / 100);
  }

  quedaDeFondo(): number {
    return Math.round((Number(this.contado ?? 0) - Number(this.retiro || 0)) * 100) / 100;
  }

  abrir(): void {
    this.enviando.set(true);
    this.api.post<{ message: string }>('cortes/abrir', { fondo: this.fondo, notas: this.notas }).subscribe({
      next: (r) => {
        this.enviando.set(false);
        this.cerrado.set(null);
        this.avisos.ok(r.message);
        this.cargar(this.hoy);
      },
      error: (e: HttpErrorResponse) => {
        this.enviando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  cerrar(): void {
    if (this.contado == null) return;
    const dif = this.diferencia();
    const aviso = dif === 0 ? '' : `\n\nOjo: ${textoDiferencia(dif)}.`;
    if (!confirm(`¿Cerrar la caja con ${this.contado} contados?${aviso}`)) return;
    this.enviando.set(true);
    this.api
      .post<{ message: string; fecha: string; corte: { turno: number; contado: string; diferencia: string; retiro: string; fondo_siguiente: string } }>(
        'cortes/cerrar', { contado: this.contado, retiro: this.retiro, notas: this.notas })
      .subscribe({
        next: (r) => {
          this.enviando.set(false);
          this.avisos.ok(r.message);
          this.cerrado.set({
            turno: r.corte.turno, fecha: r.fecha, contado: Number(r.corte.contado), diferencia: Number(r.corte.diferencia),
            retiro: Number(r.corte.retiro), fondo_siguiente: Number(r.corte.fondo_siguiente),
          });
          this.cargar(this.hoy);
        },
        error: (e: HttpErrorResponse) => {
          this.enviando.set(false);
          this.avisos.fallo(e);
        },
      });
  }
}

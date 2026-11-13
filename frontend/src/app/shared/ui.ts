import { Component, Pipe, PipeTransform, computed, input } from '@angular/core';

/** 1234.5 -> "$1,234.50" */
export function dinero(valor: number | string | null | undefined): string {
  const n = Number(valor ?? 0);
  const texto = Math.abs(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return (n < 0 ? '-$' : '$') + texto;
}

@Pipe({ name: 'dinero' })
export class DineroPipe implements PipeTransform {
  transform(valor: number | string | null | undefined): string {
    return dinero(valor);
  }
}

/** ISO o "AAAA-MM-DD" -> "dd/mm/aaaa" y, si se pide, la hora local. */
export function fecha(valor: string | null | undefined, conHora = false): string {
  if (!valor) return '';
  // una fecha sin hora no debe moverse de día por la zona horaria
  const d = /^\d{4}-\d{2}-\d{2}$/.test(valor) ? new Date(`${valor}T12:00:00`) : new Date(valor);
  if (isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  const dia = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
  return conHora ? `${dia} ${pad(d.getHours())}:${pad(d.getMinutes())}` : dia;
}

@Pipe({ name: 'fecha' })
export class FechaPipe implements PipeTransform {
  transform(valor: string | null | undefined, conHora = false): string {
    return fecha(valor, conHora);
  }
}

/** AAAA-MM-DD de hoy (u otro día) en la hora local. */
export function fechaISO(d = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Valor para un <input type="datetime-local">. */
export function fechaHoraLocal(d = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${fechaISO(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Logo de RepaPOS. "auto" toma los colores del tema; "oscuro" es para fondos siempre oscuros, como el menú. */
@Component({
  selector: 'app-logo',
  template: `
    <span class="app-logo" [class.sobre-oscuro]="fondo() === 'oscuro'" [class]="'app-logo-' + tamano()">
      <svg class="app-logo-icono" viewBox="118 84 276 310" aria-hidden="true">
        <rect x="136" y="100" width="154" height="256" rx="36" fill="none" class="tinta" stroke-width="22" />
        <g class="tinta" stroke-width="15" stroke-linecap="round">
          <line x1="192" y1="128" x2="234" y2="128" />
          <line x1="171" y1="187" x2="254" y2="187" />
          <line x1="171" y1="222" x2="232" y2="222" />
          <line x1="171" y1="258" x2="208" y2="258" />
        </g>
        <g fill="none" class="naranja" stroke-width="26" stroke-linecap="round">
          <path d="M362 250 A50 50 0 1 1 286 250" />
          <line x1="314" y1="292" x2="232" y2="376" />
          <line x1="314" y1="292" x2="314" y2="266" />
        </g>
      </svg>
      <span class="app-logo-texto">
        <span class="app-logo-nombre">Repa<span class="app-logo-pos">POS</span></span>
        @if (eslogan()) {
          <span class="app-logo-lema">Punto de venta para talleres de celulares</span>
        }
      </span>
    </span>
  `,
})
export class AppLogo {
  readonly fondo = input<'auto' | 'oscuro'>('auto');
  readonly tamano = input<'sm' | 'md' | 'lg'>('md');
  readonly eslogan = input(false);
}

const COLORES: Record<string, string> = {
  ALTA: 'badge-danger',
  MEDIA: 'badge-warning',
  PAGADO: 'badge-success',
  DEBE: 'badge-danger',
  'EN TALLER': 'badge-warning',
  LISTO: 'badge-success',
  'NO QUEDÓ': 'badge-danger',
  // liberaciones
  RECIBIDO: 'badge-warning',
  'EN PROCESO': 'badge-primary',
  RECHAZADO: 'badge-danger',
  ENTREGADO: 'badge-primary',
  DIAGNÓSTICO: 'badge-primary',
  'EN REPARACIÓN': 'badge-warning',
  'ESPERA REFACCIÓN': 'badge-warning',
  CANCELADO: 'badge-danger',
  CANCELADA: 'badge-danger',
  DEPÓSITO: 'badge-success',
  APLICADO: 'badge-primary',
  DEVUELTO: 'badge-warning',
};

/** Etiqueta de color para un estatus. */
@Component({
  selector: 'app-estatus',
  template: `<span class="badge" [class]="clase()">{{ valor() }}</span>`,
})
export class Estatus {
  readonly valor = input.required<string>();
  readonly clase = computed(() => COLORES[this.valor()] ?? '');
}

/** Dibuja un patrón de solo lectura en la ficha de la orden. */
@Component({
  selector: 'app-patron-vista',
  template: `
    <svg viewBox="0 0 180 180" width="140" height="140" class="patron-vista" role="img" [attr.aria-label]="'Patrón ' + valor()">
      <polyline [attr.points]="trazo()" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
      @for (n of puntos; track n) {
        <circle [attr.cx]="x(n)" [attr.cy]="y(n)" r="13" [class.usado]="orden(n) > 0" />
        @if (orden(n) > 0) {
          <text [attr.x]="x(n)" [attr.y]="y(n) + 4" text-anchor="middle" font-size="11" font-weight="700">{{ orden(n) }}</text>
        }
      }
    </svg>
  `,
})
export class PatronVista {
  readonly valor = input.required<string>();
  readonly puntos = [1, 2, 3, 4, 5, 6, 7, 8, 9];
  private readonly secuencia = computed(() => this.valor().split('-').map(Number));
  readonly trazo = computed(() => this.secuencia().map((n) => `${this.x(n)},${this.y(n)}`).join(' '));
  x(n: number) {
    return 30 + ((n - 1) % 3) * 60;
  }
  y(n: number) {
    return 30 + Math.floor((n - 1) / 3) * 60;
  }
  orden(n: number) {
    return this.secuencia().indexOf(n) + 1;
  }
}

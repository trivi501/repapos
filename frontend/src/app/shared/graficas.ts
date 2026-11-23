import { Component, ElementRef, OnDestroy, afterNextRender, computed, inject, input, signal } from '@angular/core';
import { dinero, fecha } from './ui';

export interface SerieGrafica {
  nombre: string;
  /** Lugar en la paleta (--serie-1, --serie-2): sigue a lo que representa, no a su posición. */
  color: 1 | 2;
  valores: number[];
}

export interface FilaGrafica {
  nombre: string;
  valor: number;
}

const ALTO = 220;
const MARGEN = { arriba: 12, derecha: 14, abajo: 26 };

/** Límites y marcas redondas del eje vertical. */
function escala(valores: number[], enteros: boolean) {
  const minimo = Math.min(0, ...valores);
  const maximo = Math.max(0, ...valores);
  const rango = maximo - minimo || 1;
  const magnitud = 10 ** Math.floor(Math.log10(rango / 4));
  const opcion = [1, 2, 5, 10].map((m) => m * magnitud).find((p) => (rango / p) <= 4) ?? 10 * magnitud;
  const paso = enteros ? Math.max(1, Math.ceil(opcion)) : opcion;
  const desde = Math.floor(minimo / paso) * paso;
  const hasta = Math.max(Math.ceil(maximo / paso) * paso, desde + paso);
  const marcas: number[] = [];
  for (let v = desde; v <= hasta + paso / 2; v += paso) marcas.push(v);
  return { desde, hasta, marcas };
}

function textoEje(valor: number, tope: number, moneda: boolean): string {
  const signo = valor < 0 ? '-' : '';
  const n = Math.abs(valor);
  const cifra = tope >= 10000 ? `${(n / 1000).toLocaleString('es-MX')}K` : n.toLocaleString('es-MX');
  return `${signo}${moneda ? '$' : ''}${cifra}`;
}

/** Cambio en el tiempo, un punto por día. Sigue al puntero y a las flechas del teclado, y se puede ver como tabla. */
@Component({
  selector: 'app-grafica-lineas',
  template: `
    <div
      class="grafica-lineas"
      tabindex="0"
      role="img"
      [attr.aria-label]="descripcion()"
      (pointermove)="seguir($event)"
      (pointerdown)="seguir($event)"
      (pointerleave)="indice.set(null)"
      (focus)="indice.set(fechas().length - 1)"
      (blur)="indice.set(null)"
      (keydown)="tecla($event)"
    >
      @if (dibujo(); as g) {
        <svg [attr.width]="g.ancho" [attr.height]="alto" aria-hidden="true">
          @for (m of g.marcas; track m.valor) {
            <line class="rejilla" [class.base]="m.valor === 0" [attr.x1]="g.izquierda" [attr.x2]="g.ancho - margen.derecha" [attr.y1]="m.y" [attr.y2]="m.y" />
            <text class="eje" [attr.x]="g.izquierda - 8" [attr.y]="m.y + 4" text-anchor="end">{{ m.texto }}</text>
          }
          @for (e of g.etiquetas; track e.x) {
            <text class="eje" [attr.x]="e.x" [attr.y]="alto - 6" [attr.text-anchor]="e.ancla">{{ e.texto }}</text>
          }
          @for (t of g.trazos; track t.color) {
            @if (t.area) {
              <path [attr.d]="t.area" [attr.fill]="'var(--serie-' + t.color + ')'" fill-opacity="0.1" />
            }
            <path class="linea" [attr.d]="t.linea" [attr.stroke]="'var(--serie-' + t.color + ')'" />
          }
          @if (indice() === null) {
            @for (t of g.trazos; track t.color) {
              <circle class="punto" [attr.cx]="t.fin.x" [attr.cy]="t.fin.y" r="4" [attr.fill]="'var(--serie-' + t.color + ')'" />
            }
          } @else {
            <line class="guia" [attr.x1]="g.xs[indice()!]" [attr.x2]="g.xs[indice()!]" [attr.y1]="margen.arriba" [attr.y2]="alto - margen.abajo" />
            @for (t of g.trazos; track t.color) {
              <circle class="punto" [attr.cx]="g.xs[indice()!]" [attr.cy]="t.ys[indice()!]" r="4" [attr.fill]="'var(--serie-' + t.color + ')'" />
            }
          }
        </svg>
        @if (indice() !== null) {
          <div class="grafica-globo" [class.al-reves]="g.xs[indice()!] > g.ancho / 2" [style.left.px]="g.xs[indice()!]">
            <div class="grafica-globo-fecha">{{ textoFecha(fechas()[indice()!]) }}</div>
            @for (s of series(); track s.nombre) {
              <div class="grafica-globo-fila">
                <span class="clave" [style.background]="'var(--serie-' + s.color + ')'"></span>
                <strong>{{ texto(s.valores[indice()!]) }}</strong>
                <span>{{ s.nombre }}</span>
              </div>
            }
          </div>
        }
      }
    </div>

    @if (series().length > 1) {
      <ul class="grafica-leyenda">
        @for (s of series(); track s.nombre) {
          <li><span class="clave" [style.background]="'var(--serie-' + s.color + ')'"></span>{{ s.nombre }}</li>
        }
      </ul>
    }

    <details class="grafica-tabla">
      <summary>Ver como tabla</summary>
      <div class="grafica-tabla-marco">
        <table>
          <thead>
            <tr>
              <th>Día</th>
              @for (s of series(); track s.nombre) {
                <th class="der">{{ s.nombre }}</th>
              }
            </tr>
          </thead>
          <tbody>
            @for (f of fechas(); track f; let i = $index) {
              <tr>
                <td>{{ textoFecha(f) }}</td>
                @for (s of series(); track s.nombre) {
                  <td class="der">{{ texto(s.valores[i]) }}</td>
                }
              </tr>
            }
          </tbody>
        </table>
      </div>
    </details>
  `,
})
export class GraficaLineas implements OnDestroy {
  readonly fechas = input.required<string[]>();
  readonly series = input.required<SerieGrafica[]>();
  readonly descripcion = input.required<string>();
  readonly moneda = input(false);

  readonly alto = ALTO;
  readonly margen = MARGEN;
  readonly indice = signal<number | null>(null);

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly ancho = signal(0);
  private observador?: ResizeObserver;

  constructor() {
    afterNextRender(() => {
      this.observador = new ResizeObserver((e) => this.ancho.set(Math.floor(e[0].contentRect.width)));
      this.observador.observe(this.host.nativeElement);
    });
  }

  ngOnDestroy(): void {
    this.observador?.disconnect();
  }

  readonly dibujo = computed(() => {
    const ancho = this.ancho();
    const fechas = this.fechas();
    const series = this.series();
    if (ancho < 80 || fechas.length < 2) return null;

    const { desde, hasta, marcas } = escala(series.flatMap((s) => s.valores), !this.moneda());
    const textos = marcas.map((m) => textoEje(m, hasta, this.moneda()));
    const izquierda = Math.max(...textos.map((t) => t.length)) * 7 + 12;
    const util = ancho - izquierda - MARGEN.derecha;
    const xs = fechas.map((_, i) => izquierda + (i * util) / (fechas.length - 1));
    const y = (v: number) => MARGEN.arriba + ((hasta - v) * (ALTO - MARGEN.arriba - MARGEN.abajo)) / (hasta - desde);

    const trazos = series.map((s) => {
      const ys = s.valores.map(y);
      const linea = ys.map((v, i) => `${i ? 'L' : 'M'}${xs[i].toFixed(1)} ${v.toFixed(1)}`).join('');
      return {
        color: s.color,
        ys,
        linea,
        // con dos líneas, el relleno ensuciaría la lectura
        area: series.length === 1 ? `${linea}L${xs[xs.length - 1].toFixed(1)} ${y(0).toFixed(1)}L${xs[0].toFixed(1)} ${y(0).toFixed(1)}Z` : null,
        fin: { x: xs[xs.length - 1], y: ys[ys.length - 1] },
      };
    });

    const medio = Math.floor((fechas.length - 1) / 2);
    const etiquetas = [
      { x: xs[0], texto: this.diaCorto(fechas[0]), ancla: 'start' },
      { x: xs[medio], texto: this.diaCorto(fechas[medio]), ancla: 'middle' },
      { x: xs[xs.length - 1], texto: this.diaCorto(fechas[fechas.length - 1]), ancla: 'end' },
    ];

    return { ancho, izquierda, xs, trazos, etiquetas, marcas: marcas.map((valor, i) => ({ valor, y: y(valor), texto: textos[i] })) };
  });

  seguir(evento: PointerEvent): void {
    const g = this.dibujo();
    if (!g) return;
    const x = evento.clientX - (evento.currentTarget as HTMLElement).getBoundingClientRect().left;
    const paso = (g.xs[g.xs.length - 1] - g.xs[0]) / (g.xs.length - 1);
    this.indice.set(Math.max(0, Math.min(g.xs.length - 1, Math.round((x - g.xs[0]) / paso))));
  }

  tecla(evento: KeyboardEvent): void {
    const cambio = evento.key === 'ArrowRight' ? 1 : evento.key === 'ArrowLeft' ? -1 : 0;
    if (!cambio) return;
    evento.preventDefault();
    const ultimo = this.fechas().length - 1;
    this.indice.set(Math.max(0, Math.min(ultimo, (this.indice() ?? ultimo) + cambio)));
  }

  texto(valor: number): string {
    return this.moneda() ? dinero(valor) : valor.toLocaleString('es-MX');
  }

  textoFecha(valor: string): string {
    return fecha(valor);
  }

  private diaCorto(valor: string): string {
    return fecha(valor).slice(0, 5);
  }
}

const ALTO_COLUMNAS = 170;
const MARGEN_COLUMNAS = { arriba: 10, derecha: 8, abajo: 24 };
const COLUMNA_MAX = 24;   // nunca más gruesa: lo que sobra de la banda es aire
const SEPARACION = 2;     // espacio del color del fondo entre columnas que se tocan

/** Un día, o una semana cuando el periodo es largo. */
interface GrupoColumnas {
  desde: string;
  hasta: string;
  valores: number[];
}

/**
 * Columnas por día (o por semana si el periodo pasa de 31 días), con una o dos series lado a lado.
 * Se lee mejor que una línea cuando hay días sin movimiento. Globo al pasar el puntero, flechas del teclado y tabla.
 */
@Component({
  selector: 'app-grafica-columnas',
  template: `
    <div
      class="grafica-columnas"
      tabindex="0"
      role="img"
      [attr.aria-label]="descripcion()"
      (pointermove)="seguir($event)"
      (pointerdown)="seguir($event)"
      (pointerleave)="indice.set(null)"
      (focus)="indice.set(grupos().length - 1)"
      (blur)="indice.set(null)"
      (keydown)="tecla($event)"
    >
      @if (dibujo(); as g) {
        <svg [attr.width]="g.ancho" [attr.height]="alto" aria-hidden="true">
          @for (m of g.marcas; track m.valor) {
            <line class="rejilla" [class.base]="m.valor === 0" [attr.x1]="g.izquierda" [attr.x2]="g.ancho - margen.derecha" [attr.y1]="m.y" [attr.y2]="m.y" />
            <text class="eje" [attr.x]="g.izquierda - 8" [attr.y]="m.y + 4" text-anchor="end">{{ m.texto }}</text>
          }
          @if (indice() !== null) {
            <rect class="banda" [attr.x]="g.izquierda + indice()! * g.banda" [attr.y]="margen.arriba" [attr.width]="g.banda" [attr.height]="alto - margen.arriba - margen.abajo" rx="4" />
          }
          @for (c of g.columnas; track c.clave) {
            <path class="columna" [attr.d]="c.d" [attr.fill]="'var(--serie-' + c.color + ')'" />
          }
          @for (e of g.etiquetas; track e.x) {
            <text class="eje" [attr.x]="e.x" [attr.y]="alto - 6" [attr.text-anchor]="e.ancla">{{ e.texto }}</text>
          }
        </svg>
        @if (indice() !== null) {
          @let grupo = grupos()[indice()!];
          @let centro = g.izquierda + (indice()! + 0.5) * g.banda;
          <div class="grafica-globo" [class.al-reves]="centro > g.ancho / 2" [style.left.px]="centro">
            <div class="grafica-globo-fecha">{{ textoGrupo(grupo) }}</div>
            @for (s of series(); track s.nombre; let i = $index) {
              <div class="grafica-globo-fila">
                <span class="clave cuadro" [style.background]="'var(--serie-' + s.color + ')'"></span>
                <strong>{{ texto(grupo.valores[i]) }}</strong>
                <span>{{ s.nombre }}</span>
              </div>
            }
          </div>
        }
      }
    </div>

    @if (series().length > 1) {
      <ul class="grafica-leyenda">
        @for (s of series(); track s.nombre) {
          <li><span class="clave cuadro" [style.background]="'var(--serie-' + s.color + ')'"></span>{{ s.nombre }}</li>
        }
      </ul>
    }

    <details class="grafica-tabla">
      <summary>Ver como tabla</summary>
      <div class="grafica-tabla-marco">
        <table>
          <thead>
            <tr>
              <th>{{ porSemana() ? 'Semana' : 'Día' }}</th>
              @for (s of series(); track s.nombre) {
                <th class="der">{{ s.nombre }}</th>
              }
            </tr>
          </thead>
          <tbody>
            @for (gr of grupos(); track gr.desde) {
              <tr>
                <td>{{ textoGrupo(gr) }}</td>
                @for (v of gr.valores; track $index) {
                  <td class="der">{{ texto(v) }}</td>
                }
              </tr>
            }
          </tbody>
        </table>
      </div>
    </details>
  `,
})
export class GraficaColumnas implements OnDestroy {
  readonly fechas = input.required<string[]>();
  readonly series = input.required<SerieGrafica[]>();
  readonly descripcion = input.required<string>();
  readonly moneda = input(false);

  readonly alto = ALTO_COLUMNAS;
  readonly margen = MARGEN_COLUMNAS;
  readonly indice = signal<number | null>(null);

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly ancho = signal(0);
  private observador?: ResizeObserver;

  constructor() {
    afterNextRender(() => {
      this.observador = new ResizeObserver((e) => this.ancho.set(Math.floor(e[0].contentRect.width)));
      this.observador.observe(this.host.nativeElement);
    });
  }

  ngOnDestroy(): void {
    this.observador?.disconnect();
  }

  /** Más de 31 días: se suman por semana para que las columnas no queden como rayitas. */
  readonly porSemana = computed(() => this.fechas().length > 31);

  readonly grupos = computed<GrupoColumnas[]>(() => {
    const fechas = this.fechas();
    const series = this.series();
    const tam = this.porSemana() ? 7 : 1;
    const grupos: GrupoColumnas[] = [];
    for (let i = 0; i < fechas.length; i += tam) {
      const fin = Math.min(i + tam, fechas.length) - 1;
      grupos.push({
        desde: fechas[i],
        hasta: fechas[fin],
        valores: series.map((s) => s.valores.slice(i, fin + 1).reduce((a, b) => a + b, 0)),
      });
    }
    return grupos;
  });

  readonly dibujo = computed(() => {
    const ancho = this.ancho();
    const grupos = this.grupos();
    const k = this.series().length;
    if (ancho < 80 || !grupos.length || !k) return null;

    const { desde, hasta, marcas } = escala(grupos.flatMap((g) => g.valores), !this.moneda());
    const textos = marcas.map((m) => textoEje(m, hasta, this.moneda()));
    const izquierda = Math.max(...textos.map((t) => t.length)) * 7 + 12;
    const util = ancho - izquierda - MARGEN_COLUMNAS.derecha;
    const banda = util / grupos.length;
    const y = (v: number) => MARGEN_COLUMNAS.arriba + ((hasta - v) * (ALTO_COLUMNAS - MARGEN_COLUMNAS.arriba - MARGEN_COLUMNAS.abajo)) / (hasta - desde);
    const base = y(0);

    // grosor: hasta 24 px por columna, y siempre aire entre un día y otro
    const grueso = Math.max(1, Math.min(COLUMNA_MAX, (banda * 0.7 - SEPARACION * (k - 1)) / k));
    const grupoAncho = grueso * k + SEPARACION * (k - 1);

    const columnas: { clave: string; color: number; d: string }[] = [];
    grupos.forEach((g, i) => {
      const inicio = izquierda + i * banda + (banda - grupoAncho) / 2;
      g.valores.forEach((v, j) => {
        if (!v) return;
        const x = inicio + j * (grueso + SEPARACION);
        const tope = y(v);
        const alto = Math.abs(base - tope);
        const r = Math.min(4, grueso / 2, alto);
        // esquina redondeada en la punta del dato, cuadrada en la base
        const d = v > 0
          ? `M${x} ${base}V${tope + r}Q${x} ${tope} ${x + r} ${tope}H${x + grueso - r}Q${x + grueso} ${tope} ${x + grueso} ${tope + r}V${base}Z`
          : `M${x} ${base}V${tope - r}Q${x} ${tope} ${x + r} ${tope}H${x + grueso - r}Q${x + grueso} ${tope} ${x + grueso} ${tope - r}V${base}Z`;
        columnas.push({ clave: `${i}-${j}`, color: this.series()[j].color, d });
      });
    });

    const centro = (i: number) => izquierda + (i + 0.5) * banda;
    const ultimo = grupos.length - 1;
    const medio = Math.floor(ultimo / 2);
    const etiquetas = (ultimo === 0 ? [0] : ultimo === 1 ? [0, 1] : [0, medio, ultimo]).map((i, n, todos) => ({
      x: n === 0 ? Math.max(izquierda, centro(i) - 14) : n === todos.length - 1 && todos.length > 1 ? Math.min(ancho - MARGEN_COLUMNAS.derecha, centro(i) + 14) : centro(i),
      texto: this.diaCorto(grupos[i].desde),
      ancla: n === 0 ? 'start' : n === todos.length - 1 && todos.length > 1 ? 'end' : 'middle',
    }));

    return { ancho, izquierda, banda, columnas, etiquetas, marcas: marcas.map((valor, i) => ({ valor, y: y(valor), texto: textos[i] })) };
  });

  seguir(evento: PointerEvent): void {
    const g = this.dibujo();
    if (!g) return;
    const x = evento.clientX - (evento.currentTarget as HTMLElement).getBoundingClientRect().left;
    const i = Math.floor((x - g.izquierda) / g.banda);
    this.indice.set(i >= 0 && i < this.grupos().length ? i : null);
  }

  tecla(evento: KeyboardEvent): void {
    const cambio = evento.key === 'ArrowRight' ? 1 : evento.key === 'ArrowLeft' ? -1 : 0;
    if (!cambio) return;
    evento.preventDefault();
    const ultimo = this.grupos().length - 1;
    this.indice.set(Math.max(0, Math.min(ultimo, (this.indice() ?? ultimo) + cambio)));
  }

  texto(valor: number): string {
    return this.moneda() ? dinero(valor) : valor.toLocaleString('es-MX');
  }

  textoGrupo(g: GrupoColumnas): string {
    return g.desde === g.hasta ? fecha(g.desde) : `Semana del ${this.diaCorto(g.desde)} al ${fecha(g.hasta)}`;
  }

  private diaCorto(valor: string): string {
    return fecha(valor).slice(0, 5);
  }
}

/** Comparar y ordenar: nombre y valor siempre a la vista, y la barra para ver la proporción. */
@Component({
  selector: 'app-grafica-barras',
  template: `
    <ul class="grafica-barras">
      @for (f of filas(); track f.nombre) {
        <li>
          <div class="grafica-barras-texto">
            <span class="nombre" [title]="f.nombre">{{ f.nombre }}</span>
            <strong>{{ texto(f.valor) }}</strong>
          </div>
          <div class="grafica-barras-pista">
            <div class="grafica-barra" [style.width.%]="ancho(f.valor)"></div>
          </div>
        </li>
      }
    </ul>
  `,
})
export class GraficaBarras {
  readonly filas = input.required<FilaGrafica[]>();
  readonly moneda = input(false);

  private readonly maximo = computed(() => Math.max(1, ...this.filas().map((f) => f.valor)));

  ancho(valor: number): number {
    return (Math.max(0, valor) / this.maximo()) * 100;
  }

  texto(valor: number): string {
    return this.moneda() ? dinero(valor) : valor.toLocaleString('es-MX');
  }
}

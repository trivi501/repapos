import { Component, ElementRef, computed, forwardRef, signal, viewChild } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

interface Punto {
  n: number;
  x: number;
  y: number;
}

// Puntos numerados como un teclado telefónico: 1 2 3 / 4 5 6 / 7 8 9, en coordenadas del viewBox 300x300.
const PUNTOS: Punto[] = Array.from({ length: 9 }, (_, i) => ({
  n: i + 1,
  x: 50 + (i % 3) * 100,
  y: 50 + Math.floor(i / 3) * 100,
}));

const RADIO_ACIERTO = 38;

/**
 * Cuadrícula de patrón 3x3 tipo Android. Funciona con mouse, toque y teclado (1-9),
 * y se integra con formularios reactivos guardando la secuencia como "1-4-7-8-9".
 */
@Component({
  selector: 'app-patron-desbloqueo',
  standalone: true,
  templateUrl: './patron-desbloqueo.html',
  styleUrl: './patron-desbloqueo.scss',
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => PatronDesbloqueo), multi: true }],
})
export class PatronDesbloqueo implements ControlValueAccessor {
  private readonly lienzo = viewChild.required<ElementRef<HTMLDivElement>>('lienzo');

  readonly puntos = PUNTOS;
  readonly secuencia = signal<number[]>([]);
  readonly trazando = signal(false);
  readonly puntero = signal<{ x: number; y: number } | null>(null);
  readonly deshabilitado = signal(false);

  readonly ultimo = computed(() => this.secuencia()[this.secuencia().length - 1]);
  readonly puntosTrazo = computed(() =>
    this.secuencia()
      .map((n) => `${this.coordenada(n).x},${this.coordenada(n).y}`)
      .join(' '),
  );

  private alCambiar: (valor: string | null) => void = () => {};
  private alTocar: () => void = () => {};

  coordenada(n: number): Punto {
    return PUNTOS[n - 1];
  }

  iniciar(evento: PointerEvent): void {
    if (this.deshabilitado()) return;
    evento.preventDefault();
    this.lienzo().nativeElement.focus();
    (evento.currentTarget as HTMLElement).setPointerCapture(evento.pointerId);
    this.secuencia.set([]);
    this.trazando.set(true);
    this.registrarPosicion(evento);
  }

  mover(evento: PointerEvent): void {
    if (!this.trazando()) return;
    this.registrarPosicion(evento);
  }

  terminar(evento: PointerEvent): void {
    if (!this.trazando()) return;
    (evento.currentTarget as HTMLElement).releasePointerCapture?.(evento.pointerId);
    this.trazando.set(false);
    this.puntero.set(null);
    this.emitir();
  }

  teclado(evento: KeyboardEvent): void {
    if (this.deshabilitado()) return;
    if (/^[1-9]$/.test(evento.key)) {
      this.agregar(Number(evento.key));
      this.emitir();
    } else if (evento.key === 'Backspace') {
      this.secuencia.update((s) => s.slice(0, -1));
      this.emitir();
    } else if (evento.key === 'Escape') {
      this.limpiar();
    } else {
      return;
    }
    evento.preventDefault();
  }

  limpiar(): void {
    this.secuencia.set([]);
    this.emitir();
  }

  tocado(): void {
    this.alTocar();
  }

  private registrarPosicion(evento: PointerEvent): void {
    const caja = this.lienzo().nativeElement.getBoundingClientRect();
    const x = ((evento.clientX - caja.left) / caja.width) * 300;
    const y = ((evento.clientY - caja.top) / caja.height) * 300;
    this.puntero.set({ x, y });

    const acierto = PUNTOS.find((p) => Math.hypot(p.x - x, p.y - y) <= RADIO_ACIERTO);
    if (acierto) this.agregar(acierto.n);
  }

  private agregar(n: number): void {
    const actual = this.secuencia();
    if (actual.includes(n)) return;

    // Igual que en Android: al saltar sobre un punto intermedio no usado (p. ej. 1→3), se incluye el 2.
    const previo = actual[actual.length - 1];
    if (previo) {
      const a = this.coordenada(previo);
      const b = this.coordenada(n);
      const medio = PUNTOS.find((p) => p.x === (a.x + b.x) / 2 && p.y === (a.y + b.y) / 2);
      if (medio && !actual.includes(medio.n)) {
        this.secuencia.set([...actual, medio.n, n]);
        return;
      }
    }
    this.secuencia.set([...actual, n]);
  }

  private emitir(): void {
    const s = this.secuencia();
    this.alCambiar(s.length ? s.join('-') : null);
  }

  writeValue(valor: string | null): void {
    const numeros = (valor ?? '')
      .split('-')
      .map(Number)
      .filter((n) => Number.isInteger(n) && n >= 1 && n <= 9);
    this.secuencia.set([...new Set(numeros)]);
  }

  registerOnChange(fn: (valor: string | null) => void): void {
    this.alCambiar = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.alTocar = fn;
  }

  setDisabledState(deshabilitado: boolean): void {
    this.deshabilitado.set(deshabilitado);
  }
}

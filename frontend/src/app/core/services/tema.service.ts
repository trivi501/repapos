import { Injectable, effect, signal } from '@angular/core';

export type Tema = 'claro' | 'oscuro';

const TEMA_KEY = 'taller_tema';

@Injectable({ providedIn: 'root' })
export class TemaService {
  readonly tema = signal<Tema>(this.leerTemaGuardado());

  constructor() {
    effect(() => {
      const tema = this.tema();
      document.documentElement.setAttribute('data-theme', tema === 'oscuro' ? 'dark' : 'light');
      try {
        localStorage.setItem(TEMA_KEY, tema);
      } catch {
        // Sin almacenamiento disponible: el tema solo dura la sesión actual.
      }
    });
  }

  alternar(): void {
    this.tema.update((t) => (t === 'oscuro' ? 'claro' : 'oscuro'));
  }

  private leerTemaGuardado(): Tema {
    try {
      return localStorage.getItem(TEMA_KEY) === 'oscuro' ? 'oscuro' : 'claro';
    } catch {
      return 'claro';
    }
  }
}

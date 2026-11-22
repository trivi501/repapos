import { Injectable, signal } from '@angular/core';

export interface OpcionesDialogo {
  titulo?: string;
  aceptar?: string;
  cancelar?: string;
  /** Botón rojo (eliminar, cancelar venta...). */
  peligro?: boolean;
}

export interface DialogoAbierto extends Required<Omit<OpcionesDialogo, 'cancelar'>> {
  mensaje: string;
  /** null = solo botón de aceptar (aviso). */
  cancelar: string | null;
  responder: (si: boolean) => void;
}

/** Reemplaza confirm() y alert() del navegador por una ventana modal del sistema. */
@Injectable({ providedIn: 'root' })
export class DialogoService {
  readonly abierto = signal<DialogoAbierto | null>(null);

  /** Pregunta Aceptar / Cancelar. Resuelve true si acepta. */
  confirmar(mensaje: string, o: OpcionesDialogo = {}): Promise<boolean> {
    const peligro = o.peligro ?? /^¿\s*(eliminar|quitar|cancelar|borrar|desvincular|suspender)/i.test(mensaje);
    return this.abrir({
      mensaje,
      titulo: o.titulo ?? 'Confirmar',
      aceptar: o.aceptar ?? (peligro ? 'Sí, continuar' : 'Aceptar'),
      cancelar: o.cancelar ?? 'Cancelar',
      peligro,
    });
  }

  /** Aviso con un solo botón. */
  alerta(mensaje: string, titulo = 'Aviso'): Promise<void> {
    return this.abrir({ mensaje, titulo, aceptar: 'Entendido', cancelar: null, peligro: false }).then(() => undefined);
  }

  private abrir(d: Omit<DialogoAbierto, 'responder'>): Promise<boolean> {
    this.abierto()?.responder(false);
    return new Promise((resolve) => {
      this.abierto.set({
        ...d,
        responder: (si) => {
          this.abierto.set(null);
          resolve(si);
        },
      });
    });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, signal } from '@angular/core';
import { textoError } from './api.service';

export interface AvisoWhatsapp {
  texto: string;
  url: string;
  sin_numero: boolean;
}

/** Mensajes después de una acción, y el botón para avisar por WhatsApp cuando un equipo queda listo. */
@Injectable({ providedIn: 'root' })
export class AvisosService {
  readonly exito = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly whatsapp = signal<AvisoWhatsapp | null>(null);

  ok(mensaje: string | null | undefined, whatsapp: AvisoWhatsapp | null = null): void {
    this.error.set(null);
    this.exito.set(mensaje ?? null);
    this.whatsapp.set(whatsapp);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  fallo(e: HttpErrorResponse | string): void {
    this.exito.set(null);
    this.whatsapp.set(null);
    this.error.set(typeof e === 'string' ? e : textoError(e));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  limpiar(): void {
    this.exito.set(null);
    this.error.set(null);
    this.whatsapp.set(null);
  }
}

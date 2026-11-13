import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject } from '@angular/core';
import { ApiService } from './api.service';
import { AuthService } from './auth.service';
import { AvisosService } from './avisos.service';

/**
 * Todos los botones de WhatsApp del sistema son enlaces wa.me con el número y el texto.
 * Si el taller conectó la API de WhatsApp Cloud, en lugar de abrir WhatsApp se manda directo desde el servidor.
 */
@Injectable({ providedIn: 'root' })
export class WhatsAppService {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly avisos = inject(AvisosService);

  readonly apiActiva = computed(() => !!this.auth.usuarioActual()?.taller?.whatsapp_api);

  /** Número y texto de un enlace https://wa.me/525512345678?text=... (sin número, va al dueño). */
  static leerEnlace(url: string): { numero: string; texto: string } | null {
    try {
      const u = new URL(url);
      if (u.hostname !== 'wa.me') return null;
      return { numero: u.pathname.replace(/\D/g, ''), texto: u.searchParams.get('text') ?? '' };
    } catch {
      return null;
    }
  }

  /** Abre WhatsApp o, con la API activa, manda el mensaje. Devuelve true si se encargó del enlace. */
  abrir(url: string): boolean {
    const datos = WhatsAppService.leerEnlace(url);
    if (!this.apiActiva() || !datos || !datos.texto) {
      window.open(url, '_blank', 'noopener');
      return false;
    }
    this.avisos.ok('Enviando por WhatsApp…');
    this.api.post<{ message: string }>('whatsapp/enviar', { numero: datos.numero || null, texto: datos.texto }).subscribe({
      next: (r) => this.avisos.ok(r.message),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
    return true;
  }

  /** Para el clic en cualquier enlace wa.me de la página. */
  interceptar(evento: MouseEvent): void {
    if (!this.apiActiva() || evento.defaultPrevented || evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey) return;
    const enlace = (evento.target as HTMLElement | null)?.closest?.('a[href^="https://wa.me/"]') as HTMLAnchorElement | null;
    if (!enlace) return;
    evento.preventDefault();
    this.abrir(enlace.href);
  }
}

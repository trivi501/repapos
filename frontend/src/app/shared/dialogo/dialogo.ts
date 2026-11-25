import { Component, ElementRef, effect, inject, viewChild } from '@angular/core';
import { AvisosService } from '../../core/services/avisos.service';
import { DialogoService } from '../../core/services/dialogo.service';
import { WhatsAppService } from '../../core/services/whatsapp.service';

/** Ventanas modales de confirmación/aviso y la de "¿Avisar por WhatsApp?". Va una sola vez en la raíz. */
@Component({
  selector: 'app-dialogo',
  template: `
    @if (dialogo.abierto(); as d) {
      <div class="modal-backdrop dialogo-fondo no-imprimir" (click)="$event.target === $event.currentTarget ? d.responder(false) : null">
        <div class="modal modal-dialogo" role="alertdialog" aria-modal="true" aria-labelledby="dialogo-titulo" aria-describedby="dialogo-texto"
             (keydown.escape)="d.responder(false)">
          <div class="modal-header">
            <h2 id="dialogo-titulo">{{ d.titulo }}</h2>
          </div>
          <p id="dialogo-texto" class="dialogo-texto">{{ d.mensaje }}</p>
          <div class="modal-actions">
            @if (d.cancelar) {
              <button class="btn btn-secondary" type="button" (click)="d.responder(false)">{{ d.cancelar }}</button>
            }
            <button #aceptar class="btn" [class.btn-danger]="d.peligro" [class.btn-primary]="!d.peligro" type="button"
                    (click)="d.responder(true)">{{ d.aceptar }}</button>
          </div>
        </div>
      </div>
    } @else if (avisos.whatsapp(); as w) {
      <div class="modal-backdrop dialogo-fondo no-imprimir" (click)="$event.target === $event.currentTarget ? avisos.whatsapp.set(null) : null">
        <div class="modal modal-dialogo" role="dialog" aria-modal="true" aria-labelledby="whatsapp-titulo" (keydown.escape)="avisos.whatsapp.set(null)">
          <div class="modal-header">
            <h2 id="whatsapp-titulo">¿Avisar por WhatsApp?</h2>
          </div>
          <p class="dialogo-texto">{{ w.texto }}</p>
          @if (w.sin_numero) {
            <p class="ayuda">No tiene número guardado: elegirás el contacto en WhatsApp.</p>
          }
          <div class="modal-actions">
            <button class="btn btn-secondary" type="button" (click)="avisos.whatsapp.set(null)">Ahora no</button>
            <button #aceptar class="btn btn-whatsapp" type="button" (click)="enviar(w.url)">Enviar WhatsApp</button>
          </div>
        </div>
      </div>
    }
  `,
})
export class Dialogo {
  readonly dialogo = inject(DialogoService);
  readonly avisos = inject(AvisosService);
  private readonly whatsapp = inject(WhatsAppService);
  private readonly boton = viewChild<ElementRef<HTMLButtonElement>>('aceptar');

  constructor() {
    // al abrir, el foco va al botón principal (Enter acepta, Esc cancela)
    effect(() => {
      const b = this.boton();
      if (b) setTimeout(() => b.nativeElement.focus());
    });
  }

  enviar(url: string): void {
    this.avisos.whatsapp.set(null);
    this.whatsapp.abrir(url);
  }
}

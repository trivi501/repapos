import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { Dialogo } from './shared/dialogo/dialogo';
import { TemaService } from './core/services/tema.service';
import { WhatsAppService } from './core/services/whatsapp.service';
import { SelectBuscableService } from './core/services/select-buscable.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, Dialogo],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  // con la API de WhatsApp activa, cualquier botón wa.me manda el mensaje directo en vez de abrir WhatsApp
  host: { '(document:click)': 'whatsapp.interceptar($event)' },
})
export class App {
  // Se inyecta aquí para aplicar el tema guardado desde el arranque, incluso en el login.
  private readonly temaService = inject(TemaService);
  protected readonly whatsapp = inject(WhatsAppService);

  constructor() {
    // todas las listas desplegables de la app con buscador
    inject(SelectBuscableService).iniciar();
  }
}

import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TemaService } from './core/services/tema.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  // Se inyecta aquí para aplicar el tema guardado desde el arranque, incluso en el login.
  private readonly temaService = inject(TemaService);
}

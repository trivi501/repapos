import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Usuario } from '../../core/models/auth.model';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';

@Component({
  selector: 'app-perfil',
  imports: [FormsModule],
  template: `
    <div class="encabezado"><h1>Mi perfil</h1></div>
    <form class="card contenido ancho-3xl" (ngSubmit)="guardar()">
      <div class="rejilla-2">
        <div class="form-field">
          <label for="name">Nombre</label>
          <input id="name" name="name" required autocomplete="name" [(ngModel)]="m.name" />
        </div>
        <div class="form-field">
          <label for="email">Correo (con él inicias sesión)</label>
          <input id="email" name="email" type="email" required autocomplete="username" [(ngModel)]="m.email" />
        </div>
      </div>
      <h2>Cambiar contraseña</h2>
      <p class="ayuda">Déjalo vacío si no quieres cambiarla.</p>
      <div class="rejilla-3">
        <div class="form-field">
          <label for="password_actual">Contraseña actual</label>
          <input id="password_actual" name="password_actual" type="password" autocomplete="current-password" [(ngModel)]="m.password_actual" />
        </div>
        <div class="form-field">
          <label for="password">Nueva contraseña</label>
          <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" [(ngModel)]="m.password" />
        </div>
        <div class="form-field">
          <label for="password_confirmation">Repítela</label>
          <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" [(ngModel)]="m.password_confirmation" />
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
  `,
})
export class Perfil {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly auth = inject(AuthService);

  m = {
    name: this.auth.usuarioActual()?.name ?? '',
    email: this.auth.usuarioActual()?.email ?? '',
    password_actual: '',
    password: '',
    password_confirmation: '',
  };

  guardar(): void {
    this.api.put<{ message: string; user: Usuario }>('perfil', this.m).subscribe({
      next: (r) => {
        this.auth.actualizarUsuario(r.user);
        this.m = { ...this.m, password_actual: '', password: '', password_confirmation: '' };
        this.avisos.ok(r.message);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

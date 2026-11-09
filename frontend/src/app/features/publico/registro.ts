import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ApiService, textoError } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { AppLogo } from '../../shared/ui';
import { Producto } from './inicio';

@Component({
  selector: 'app-registro',
  imports: [FormsModule, RouterLink, AppLogo],
  styleUrl: '../login/login.scss',
  template: `
    <div class="login-page">
      <form class="card login-card" (ngSubmit)="enviar()">
        <div class="login-brand">
          <app-logo tamano="md" />
          <h1>Crea la cuenta de tu taller</h1>
          <p>{{ diasPrueba() }} días gratis, sin tarjeta.</p>
        </div>
        @if (error()) {
          <div class="alert alert-error" role="alert">{{ error() }}</div>
        }
        <div class="form-field"><label for="taller">Nombre del taller</label><input id="taller" name="taller" required [(ngModel)]="m.taller" /></div>
        <div class="form-field"><label for="whatsapp">WhatsApp del taller (opcional)</label><input id="whatsapp" name="whatsapp" inputmode="tel" placeholder="10 dígitos" [(ngModel)]="m.whatsapp" /></div>
        <div class="form-field"><label for="name">Tu nombre</label><input id="name" name="name" required autocomplete="name" [(ngModel)]="m.name" /></div>
        <div class="form-field"><label for="email">Correo (con él vas a entrar)</label><input id="email" name="email" type="email" required autocomplete="username" [(ngModel)]="m.email" /></div>
        <div class="form-field"><label for="password">Contraseña</label><input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" [(ngModel)]="m.password" /></div>
        <div class="form-field"><label for="password_confirmation">Repítela</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" [(ngModel)]="m.password_confirmation" /></div>
        <button class="btn btn-primary login-submit" type="submit" [disabled]="enviando()">{{ enviando() ? 'Creando…' : 'Empezar prueba gratis' }}</button>
        <p class="login-consulta">¿Ya tienes cuenta? <a routerLink="/login">Entrar</a></p>
      </form>
    </div>
  `,
})
export class Registro implements OnInit {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly avisos = inject(AvisosService);
  private readonly router = inject(Router);

  readonly diasPrueba = signal(15);
  readonly error = signal<string | null>(null);
  readonly enviando = signal(false);

  m = { taller: '', whatsapp: '', name: '', email: '', password: '', password_confirmation: '' };

  ngOnInit(): void {
    this.api.get<Producto>('producto').subscribe((p) => this.diasPrueba.set(p.dias_prueba));
  }

  enviar(): void {
    this.enviando.set(true);
    this.error.set(null);
    this.auth.registrar({ ...this.m, whatsapp: this.m.whatsapp || null }).subscribe({
      next: (r) => this.router.navigateByUrl(this.auth.rutaInicial()).then(() => this.avisos.ok(r.message)),
      error: (e: HttpErrorResponse) => {
        this.enviando.set(false);
        this.error.set(textoError(e));
      },
    });
  }
}

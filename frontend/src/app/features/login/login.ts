import { Component, inject, signal } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { AppLogo } from '../../shared/ui';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink, AppLogo],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly fb = inject(FormBuilder);
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);

  readonly formulario = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  enviar() {
    if (this.formulario.invalid) {
      this.formulario.markAllAsTouched();
      return;
    }

    this.cargando.set(true);
    this.error.set(null);

    const { email, password } = this.formulario.getRawValue();

    this.authService.login(email!, password!).subscribe({
      next: () => {
        this.cargando.set(false);
        this.router.navigateByUrl(this.authService.rutaInicial());
      },
      error: () => {
        this.cargando.set(false);
        this.error.set('Correo o contraseña incorrectos.');
      },
    });
  }
}

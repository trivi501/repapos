import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const router = inject(Router);
  const token = authService.obtenerToken();
  const sucursal = authService.sucursalCabecera();

  const solicitud = token
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}`, ...(sucursal ? { 'X-Sucursal': sucursal } : {}) } })
    : req;

  return next(solicitud).pipe(
    catchError((error) => {
      if (error.status === 401 && token) {
        authService.limpiarSesion();
      }
      // suscripción vencida: solo se puede ver la pantalla de suscripción
      if (error.status === 402) {
        router.navigate(['/suscripcion']);
      }
      return throwError(() => error);
    }),
  );
};

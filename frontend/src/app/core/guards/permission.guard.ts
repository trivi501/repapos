import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

export const permissionGuard: CanActivateFn = (route) => {
  const authService = inject(AuthService);
  const router = inject(Router);
  const permisosRequeridos = (route.data['permisos'] as string[] | undefined) ?? [];

  if (authService.tieneAlgunPermiso(permisosRequeridos)) {
    return true;
  }

  return router.createUrlTree(['/dashboard']);
};

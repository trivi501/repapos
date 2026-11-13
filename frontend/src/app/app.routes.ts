import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { permissionGuard } from './core/guards/permission.guard';
import { AuthService } from './core/services/auth.service';

/** La página de venta es para visitantes: quien ya inició sesión va a su pantalla de trabajo. */
const soloVisitantes: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.estaAutenticado() ? inject(Router).parseUrl(auth.rutaInicial()) : true;
};

const conPermiso = (...permisos: string[]) => ({ canActivate: [permissionGuard], data: { permisos } });

export const routes: Routes = [
  // ---- públicas
  {
    path: '',
    pathMatch: 'full',
    canActivate: [soloVisitantes],
    loadComponent: () => import('./features/publico/inicio').then((m) => m.Inicio),
  },
  {
    path: 'login',
    loadComponent: () => import('./features/login/login').then((m) => m.Login),
  },
  {
    path: 'registro',
    canActivate: [soloVisitantes],
    loadComponent: () => import('./features/publico/registro').then((m) => m.Registro),
  },
  {
    // el cliente llega aquí con el enlace de su comprobante o de WhatsApp
    path: 'orden/:token',
    loadComponent: () => import('./features/publico/orden-estado').then((m) => m.OrdenEstado),
  },
  {
    // el cliente consulta su reparación sin iniciar sesión
    path: 'consulta',
    loadComponent: () => import('./features/consulta/consulta').then((m) => m.Consulta),
  },
  {
    path: 'consulta/:folio',
    loadComponent: () => import('./features/consulta/consulta').then((m) => m.Consulta),
  },

  // ---- para imprimir: sin el menú del sistema
  {
    // el comprobante se imprime en ticket de 80 mm; la hoja carta queda como opción
    path: 'reparaciones/:id/recibo',
    canActivate: [authGuard, permissionGuard],
    data: { permisos: ['ordenes.ver'] },
    loadComponent: () => import('./features/reparaciones/recibo-ticket').then((m) => m.ReciboTicket),
  },
  {
    path: 'reparaciones/:id/recibo-hoja',
    canActivate: [authGuard, permissionGuard],
    data: { permisos: ['ordenes.ver'] },
    loadComponent: () => import('./features/reparaciones/recibo').then((m) => m.Recibo),
  },
  {
    path: 'ventas/:id/ticket',
    canActivate: [authGuard, permissionGuard],
    data: { permisos: ['ventas.ver'] },
    loadComponent: () => import('./features/ventas/venta-ticket').then((m) => m.VentaTicket),
  },
  {
    path: 'corte/ticket',
    canActivate: [authGuard, permissionGuard],
    data: { permisos: ['cortes.ver'] },
    loadComponent: () => import('./features/corte/corte-ticket').then((m) => m.CorteTicket),
  },

  // ---- sistema
  {
    path: '',
    loadComponent: () => import('./layout/shell/shell').then((m) => m.Shell),
    canActivate: [authGuard],
    children: [
      { path: 'dashboard', loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard) },
      { path: 'perfil', loadComponent: () => import('./features/perfil/perfil').then((m) => m.Perfil) },
      { path: 'suscripcion', loadComponent: () => import('./features/suscripcion/suscripcion').then((m) => m.Suscripcion) },

      // locatarios
      { path: 'telefonos', ...conPermiso('telefonos.ver'), loadComponent: () => import('./features/telefonos/telefonos').then((m) => m.Telefonos) },
      // el alta y la edición ahora son una ventana sobre la lista; las direcciones viejas la abren
      { path: 'telefonos/nuevo', redirectTo: () => inject(Router).createUrlTree(['/telefonos'], { queryParams: { nuevo: 1 } }) },
      {
        path: 'telefonos/:id/editar',
        redirectTo: ({ params }) => inject(Router).createUrlTree(['/telefonos'], { queryParams: { editar: params['id'] } }),
      },
      { path: 'resumen', ...conPermiso('resumen.ver'), loadComponent: () => import('./features/resumen/resumen').then((m) => m.Resumen) },
      { path: 'saldos', ...conPermiso('saldos.ver'), loadComponent: () => import('./features/saldos/saldos').then((m) => m.Saldos) },

      // clientes finales
      { path: 'clientes', ...conPermiso('clientes.ver'), loadComponent: () => import('./features/clientes/clientes').then((m) => m.Clientes) },
      { path: 'equipos', ...conPermiso('equipos.ver'), loadComponent: () => import('./features/equipos/equipos').then((m) => m.Equipos) },
      { path: 'reparaciones', ...conPermiso('ordenes.ver'), loadComponent: () => import('./features/ordenes/ordenes').then((m) => m.Ordenes) },
      { path: 'reparaciones/crear', ...conPermiso('ordenes.crear'), loadComponent: () => import('./features/reparaciones/reparacion-form').then((m) => m.ReparacionForm) },
      { path: 'reparaciones/:id', ...conPermiso('ordenes.ver'), loadComponent: () => import('./features/reparaciones/reparacion-detalle').then((m) => m.ReparacionDetalle) },
      { path: 'reparaciones/:id/editar', ...conPermiso('ordenes.editar', 'ordenes.cambiar_estado'), loadComponent: () => import('./features/reparaciones/reparacion-form').then((m) => m.ReparacionForm) },
      { path: 'ordenes', redirectTo: 'reparaciones' },

      // ventas e inventario
      { path: 'ventas', ...conPermiso('ventas.ver'), loadComponent: () => import('./features/ventas/ventas').then((m) => m.Ventas) },
      { path: 'ventas/nueva', ...conPermiso('ventas.crear'), loadComponent: () => import('./features/ventas/venta-nueva').then((m) => m.VentaNueva) },
      { path: 'ventas/:id', ...conPermiso('ventas.ver'), loadComponent: () => import('./features/ventas/venta-detalle').then((m) => m.VentaDetalle) },
      { path: 'inventario', ...conPermiso('inventario.ver'), loadComponent: () => import('./features/inventario/inventario').then((m) => m.Inventario) },
      { path: 'inventario/nuevo', ...conPermiso('inventario.crear'), loadComponent: () => import('./features/inventario/producto-form').then((m) => m.ProductoForm) },
      { path: 'inventario/:id/editar', ...conPermiso('inventario.editar'), loadComponent: () => import('./features/inventario/producto-form').then((m) => m.ProductoForm) },
      { path: 'inventario/:id/kardex', ...conPermiso('inventario.ver'), loadComponent: () => import('./features/inventario/kardex').then((m) => m.Kardex) },

      // caja
      { path: 'corte', ...conPermiso('cortes.ver'), loadComponent: () => import('./features/corte/corte').then((m) => m.Corte) },
      { path: 'corte/historial', ...conPermiso('cortes.ver'), loadComponent: () => import('./features/corte/corte-historial').then((m) => m.CorteHistorial) },
      { path: 'salidas', ...conPermiso('salidas.ver'), loadComponent: () => import('./features/salidas/salidas').then((m) => m.Salidas) },

      // ajustes
      { path: 'configuracion', ...conPermiso('configuracion.ver'), loadComponent: () => import('./features/configuracion/configuracion').then((m) => m.Configuracion) },
      { path: 'usuarios', ...conPermiso('usuarios.ver'), loadComponent: () => import('./features/usuarios/usuarios').then((m) => m.Usuarios) },
      { path: 'sucursales', ...conPermiso('sucursales.ver'), loadComponent: () => import('./features/sucursales/sucursales').then((m) => m.Sucursales) },
      { path: 'roles', ...conPermiso('roles.crear'), loadComponent: () => import('./features/roles/roles').then((m) => m.Roles) },

      // dueño del sistema
      { path: 'admin/talleres', ...conPermiso('talleres.administrar'), loadComponent: () => import('./features/admin/talleres').then((m) => m.Talleres) },
    ],
  },
  { path: '**', redirectTo: '' },
];

import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { NavigationStart, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { TemaService } from '../../core/services/tema.service';
import { AppLogo } from '../../shared/ui';

interface ItemMenu {
  ruta: string;
  etiqueta: string;
  icono: string;
  permisos: string[];
}

interface GrupoMenu {
  titulo: string;
  items: ItemMenu[];
}

const MENU: GrupoMenu[] = [
  { titulo: '', items: [{ ruta: '/dashboard', etiqueta: 'Panel', icono: '🏠', permisos: [] }] },
  {
    titulo: 'Locatarios',
    items: [
      { ruta: '/telefonos', etiqueta: 'Teléfonos', icono: '📲', permisos: ['telefonos.ver'] },
      { ruta: '/resumen', etiqueta: 'Resumen por locatario', icono: '📊', permisos: ['resumen.ver'] },
      { ruta: '/saldos', etiqueta: 'Saldos a favor', icono: '💰', permisos: ['saldos.ver'] },
    ],
  },
  {
    titulo: 'Clientes',
    items: [
      { ruta: '/reparaciones', etiqueta: 'Reparaciones', icono: '🛠️', permisos: ['ordenes.ver'] },
      { ruta: '/clientes', etiqueta: 'Clientes', icono: '👤', permisos: ['clientes.ver'] },
      { ruta: '/equipos', etiqueta: 'Equipos', icono: '📱', permisos: ['equipos.ver'] },
    ],
  },
  {
    titulo: 'Ventas',
    items: [
      { ruta: '/ventas/nueva', etiqueta: 'Nueva venta', icono: '🛒', permisos: ['ventas.crear'] },
      { ruta: '/ventas', etiqueta: 'Ventas del día', icono: '🧾', permisos: ['ventas.ver'] },
      { ruta: '/inventario', etiqueta: 'Inventario', icono: '📦', permisos: ['inventario.ver'] },
    ],
  },
  {
    titulo: 'Caja',
    items: [
      { ruta: '/corte', etiqueta: 'Corte de caja', icono: '💵', permisos: ['cortes.ver'] },
      { ruta: '/salidas', etiqueta: 'Salidas de dinero', icono: '💸', permisos: ['salidas.ver'] },
    ],
  },
  {
    titulo: 'Ajustes',
    items: [
      { ruta: '/configuracion', etiqueta: 'Configuración y WhatsApp', icono: '⚙️', permisos: ['configuracion.ver'] },
      { ruta: '/whatsapp/mensajes', etiqueta: 'Mensajes de WhatsApp', icono: '💬', permisos: ['configuracion.ver'] },
      { ruta: '/whatsapp/locatarios', etiqueta: 'WhatsApp de locatarios', icono: '📇', permisos: ['configuracion.ver'] },
      { ruta: '/usuarios', etiqueta: 'Usuarios', icono: '🧑‍💼', permisos: ['usuarios.ver'] },
      { ruta: '/sucursales', etiqueta: 'Sucursales', icono: '🏬', permisos: ['sucursales.ver'] },
      { ruta: '/roles', etiqueta: 'Roles y permisos', icono: '🔐', permisos: ['roles.crear'] },
      { ruta: '/suscripcion', etiqueta: 'Suscripción', icono: '💳', permisos: [] },
    ],
  },
  { titulo: 'Sistema', items: [{ ruta: '/admin/talleres', etiqueta: 'Talleres', icono: '★', permisos: ['talleres.administrar'] }] },
];

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, AppLogo],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell implements OnInit {
  readonly auth = inject(AuthService);
  readonly avisos = inject(AvisosService);
  readonly temaService = inject(TemaService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly menuAbierto = signal(false);

  readonly menu = computed(() =>
    MENU.map((g) => ({ titulo: g.titulo, items: g.items.filter((i) => this.auth.tieneAlgunPermiso(i.permisos)) })).filter((g) => g.items.length),
  );
  readonly usuario = this.auth.usuarioActual;
  readonly taller = computed(() => this.usuario()?.taller);
  readonly inicio = computed(() => this.auth.rutaInicial());

  ngOnInit(): void {
    // los avisos no deben quedarse pegados al cambiar de pantalla
    this.router.events.subscribe((e) => {
      if (e instanceof NavigationStart) this.avisos.limpiar();
    });
    // roles y permisos pudieron cambiar desde que inició sesión
    this.auth.refrescar().subscribe();
  }

  respaldo(): void {
    this.api.descargar('respaldo', `respaldo_${new Date().toISOString().slice(0, 10)}.json.gz`);
  }

  cerrarSesion() {
    this.auth.logout();
  }
}

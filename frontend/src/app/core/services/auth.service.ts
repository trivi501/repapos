import { HttpClient } from '@angular/common/http';
import { Injectable, computed, signal } from '@angular/core';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { LoginResponse, Usuario } from '../models/auth.model';

const TOKEN_KEY = 'taller_token';
const USER_KEY = 'taller_user';
const SUCURSAL_KEY = 'taller_sucursal';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly usuario = signal<Usuario | null>(this.leerUsuarioGuardado());
  private readonly token = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  private readonly sucursalElegida = signal<number | null>(Number(localStorage.getItem(SUCURSAL_KEY)) || null);

  readonly usuarioActual = this.usuario.asReadonly();
  readonly estaAutenticado = computed(() => !!this.token());
  readonly permisos = computed(() => new Set(this.usuario()?.permissions ?? []));
  readonly roles = computed(() => new Set(this.usuario()?.roles ?? []));

  /** Sucursal en la que se trabaja: la fija del usuario, la que eligió, o la primera de su taller. */
  readonly sucursalActual = computed(() => {
    const u = this.usuario();
    const lista = u?.sucursales ?? [];
    const id = u?.sucursal_id ?? this.sucursalElegida();
    return lista.find((s) => s.id === id) ?? lista[0] ?? null;
  });

  /** Lista para el selector: vacía si el usuario está fijo a una sucursal o solo hay una. */
  readonly sucursalesElegibles = computed(() => {
    const u = this.usuario();
    return !u || u.sucursal_id || (u.sucursales?.length ?? 0) < 2 ? [] : u.sucursales!;
  });

  constructor(
    private readonly http: HttpClient,
    private readonly router: Router,
  ) {}

  private guardarSesion(respuesta: LoginResponse): void {
    this.token.set(respuesta.token);
    localStorage.setItem(TOKEN_KEY, respuesta.token);
    this.fijarUsuario(respuesta.user);
  }

  private fijarUsuario(usuario: Usuario): void {
    this.usuario.set(usuario);
    localStorage.setItem(USER_KEY, JSON.stringify(usuario));
  }

  login(email: string, password: string) {
    return this.http.post<LoginResponse>(`${environment.apiUrl}/login`, { email, password }).pipe(tap((r) => this.guardarSesion(r)));
  }

  registrar(datos: Record<string, unknown>) {
    return this.http.post<LoginResponse>(`${environment.apiUrl}/registro`, datos).pipe(tap((r) => this.guardarSesion(r)));
  }

  /** Vuelve a pedir roles, permisos y estado del taller (pudieron cambiar desde el último inicio de sesión). */
  refrescar() {
    return this.http.get<Usuario>(`${environment.apiUrl}/me`).pipe(tap((u) => this.fijarUsuario(u)));
  }

  actualizarUsuario(usuario: Usuario): void {
    this.fijarUsuario(usuario);
  }

  /** Cambia la sucursal de trabajo y recarga: todo lo que se ve depende de ella. */
  cambiarSucursal(id: number): void {
    this.sucursalElegida.set(id);
    localStorage.setItem(SUCURSAL_KEY, String(id));
    window.location.reload();
  }

  /** Cabecera que le dice a la API en qué sucursal se trabaja. */
  sucursalCabecera(): string | null {
    const u = this.usuario();
    if (!u || u.sucursal_id) return null;
    return this.sucursalElegida() ? String(this.sucursalElegida()) : null;
  }

  logout() {
    this.http.post(`${environment.apiUrl}/logout`, {}).subscribe({
      next: () => this.limpiarSesion(),
      error: () => this.limpiarSesion(),
    });
  }

  limpiarSesion() {
    this.token.set(null);
    this.usuario.set(null);
    this.sucursalElegida.set(null);
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(SUCURSAL_KEY);
    this.router.navigate(['/login']);
  }

  obtenerToken(): string | null {
    return this.token();
  }

  tienePermiso(permiso: string): boolean {
    return this.permisos().has(permiso);
  }

  tieneAlgunPermiso(permisos: string[]): boolean {
    if (permisos.length === 0) return true;
    return permisos.some((permiso) => this.permisos().has(permiso));
  }

  /** Primera pantalla de trabajo según lo que el usuario puede ver. */
  rutaInicial(): string {
    if (this.usuario()?.taller && !this.usuario()?.taller?.vigente && !this.usuario()?.es_superadmin) return '/suscripcion';
    if (this.tienePermiso('telefonos.ver')) return '/telefonos';
    if (this.tienePermiso('ordenes.ver')) return '/reparaciones';
    return '/dashboard';
  }

  private leerUsuarioGuardado(): Usuario | null {
    const crudo = localStorage.getItem(USER_KEY);
    return crudo ? (JSON.parse(crudo) as Usuario) : null;
  }
}

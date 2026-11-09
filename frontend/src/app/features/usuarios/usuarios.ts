import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { UsuarioService } from '../../core/services/usuario.service';
import { RolService } from '../../core/services/rol.service';
import { AuthService } from '../../core/services/auth.service';
import { GRAFICAS_BASICAS, GRAFICAS_PANEL, Rol, Sucursal, UsuarioAdmin } from '../../core/models/taller.model';
import { OrdenService } from '../../core/services/orden.service';
import { HttpErrorResponse } from '@angular/common/http';
import { textoError } from '../../core/services/api.service';

@Component({
  selector: 'app-usuarios',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './usuarios.html',
  styleUrl: './usuarios.scss',
})
export class Usuarios implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly usuarioService = inject(UsuarioService);
  private readonly rolService = inject(RolService);
  private readonly ordenService = inject(OrdenService);
  readonly authService = inject(AuthService);

  readonly usuarios = signal<UsuarioAdmin[]>([]);
  readonly roles = signal<Rol[]>([]);
  readonly sucursales = signal<Sucursal[]>([]);
  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);
  readonly modalAbierto = signal(false);
  readonly usuarioEditando = signal<UsuarioAdmin | null>(null);
  readonly pagina = signal(1);
  readonly totalPaginas = signal(1);
  readonly termino = signal('');
  readonly graficas = GRAFICAS_PANEL;

  readonly formulario = this.fb.group({
    name: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    password: [''],
    sucursal_id: [null as number | null],
    roles: this.fb.control<string[]>([]),
    panel: this.fb.control<string[]>([]),
  });

  ngOnInit(): void {
    this.rolService.listar().subscribe((roles) => this.roles.set(roles));
    this.ordenService.listarSucursales().subscribe((s) => this.sucursales.set(s));
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.usuarioService.listar(this.pagina(), this.termino()).subscribe({
      next: (respuesta) => {
        this.usuarios.set(respuesta.data);
        this.totalPaginas.set(respuesta.last_page);
        this.cargando.set(false);
      },
      error: () => this.cargando.set(false),
    });
  }

  buscar(valor: string): void {
    this.termino.set(valor);
    this.pagina.set(1);
    this.cargar();
  }

  cambiarPagina(delta: number): void {
    const nueva = this.pagina() + delta;
    if (nueva < 1 || nueva > this.totalPaginas()) return;
    this.pagina.set(nueva);
    this.cargar();
  }

  abrirCrear(): void {
    this.usuarioEditando.set(null);
    this.formulario.reset({ roles: [], panel: [...GRAFICAS_BASICAS] });
    this.formulario.controls.password.setValidators([Validators.required, Validators.minLength(8)]);
    this.formulario.controls.password.updateValueAndValidity();
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  abrirEditar(usuario: UsuarioAdmin): void {
    this.usuarioEditando.set(usuario);
    this.formulario.reset({
      name: usuario.name,
      email: usuario.email,
      password: '',
      sucursal_id: usuario.sucursal_id ?? null,
      roles: usuario.roles.map((r) => r.name),
      panel: usuario.panel ?? [],
    });
    this.formulario.controls.password.setValidators([Validators.minLength(8)]);
    this.formulario.controls.password.updateValueAndValidity();
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  cerrarModal(): void {
    this.modalAbierto.set(false);
  }

  alternarRol(nombreRol: string, marcado: boolean): void {
    const actuales = this.formulario.controls.roles.value ?? [];
    this.formulario.controls.roles.setValue(
      marcado ? [...actuales, nombreRol] : actuales.filter((r) => r !== nombreRol),
    );
  }

  rolSeleccionado(nombreRol: string): boolean {
    return (this.formulario.controls.roles.value ?? []).includes(nombreRol);
  }

  /** Si el rol ya la incluye, no se puede quitar desde aquí. */
  graficaPorRol(permiso: string): boolean {
    return this.roles().some((r) => this.rolSeleccionado(r.name) && r.permissions?.some((p) => p.name === permiso));
  }

  graficaSeleccionada(permiso: string): boolean {
    return (this.formulario.controls.panel.value ?? []).includes(permiso);
  }

  alternarGrafica(permiso: string, marcada: boolean): void {
    const actuales = this.formulario.controls.panel.value ?? [];
    this.formulario.controls.panel.setValue(marcada ? [...actuales, permiso] : actuales.filter((p) => p !== permiso));
  }

  guardar(): void {
    if (this.formulario.invalid) {
      this.formulario.markAllAsTouched();
      return;
    }

    const datos = this.formulario.getRawValue();
    const editando = this.usuarioEditando();

    const peticion = editando
      ? this.usuarioService.actualizar(editando.id, {
          name: datos.name!,
          email: datos.email!,
          password: datos.password || undefined,
          sucursal_id: datos.sucursal_id,
          roles: datos.roles ?? [],
          panel: datos.panel ?? [],
        })
      : this.usuarioService.crear({
          name: datos.name!,
          email: datos.email!,
          password: datos.password!,
          sucursal_id: datos.sucursal_id,
          roles: datos.roles ?? [],
          panel: datos.panel ?? [],
        });

    peticion.subscribe({
      next: () => {
        this.modalAbierto.set(false);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.error.set(textoError(e, 'No se pudo guardar el usuario.')),
    });
  }

  eliminar(usuario: UsuarioAdmin): void {
    if (!confirm(`¿Eliminar al usuario "${usuario.name}"?`)) return;
    this.usuarioService.eliminar(usuario.id).subscribe({
      next: () => this.cargar(),
      error: (e: HttpErrorResponse) => alert(textoError(e, 'No se pudo eliminar el usuario.')),
    });
  }
}

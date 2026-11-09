import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RolService } from '../../core/services/rol.service';
import { Permiso, Rol } from '../../core/models/taller.model';

@Component({
  selector: 'app-roles',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './roles.html',
  styleUrl: './roles.scss',
})
export class Roles implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly rolService = inject(RolService);

  readonly roles = signal<Rol[]>([]);
  readonly permisos = signal<Permiso[]>([]);
  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);
  readonly modalAbierto = signal(false);
  readonly rolEditando = signal<Rol | null>(null);

  readonly modulos = computed(() => {
    const grupos = new Map<string, Permiso[]>();
    for (const permiso of this.permisos()) {
      const [modulo] = permiso.name.split('.');
      if (!grupos.has(modulo)) grupos.set(modulo, []);
      grupos.get(modulo)!.push(permiso);
    }
    return Array.from(grupos.entries()).map(([modulo, permisos]) => ({ modulo, permisos }));
  });

  readonly formulario = this.fb.group({
    name: ['', Validators.required],
    permissions: this.fb.control<string[]>([]),
  });

  ngOnInit(): void {
    this.rolService.listarPermisos().subscribe((permisos) => this.permisos.set(permisos));
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.rolService.listar().subscribe({
      next: (roles) => {
        this.roles.set(roles);
        this.cargando.set(false);
      },
      error: () => this.cargando.set(false),
    });
  }

  abrirCrear(): void {
    this.rolEditando.set(null);
    this.formulario.reset({ permissions: [] });
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  abrirEditar(rol: Rol): void {
    this.rolEditando.set(rol);
    this.formulario.reset({
      name: rol.name,
      permissions: (rol.permissions ?? []).map((p) => p.name),
    });
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  cerrarModal(): void {
    this.modalAbierto.set(false);
  }

  alternarPermiso(nombrePermiso: string, marcado: boolean): void {
    const actuales = this.formulario.controls.permissions.value ?? [];
    this.formulario.controls.permissions.setValue(
      marcado ? [...actuales, nombrePermiso] : actuales.filter((p) => p !== nombrePermiso),
    );
  }

  permisoSeleccionado(nombrePermiso: string): boolean {
    return (this.formulario.controls.permissions.value ?? []).includes(nombrePermiso);
  }

  guardar(): void {
    if (this.formulario.invalid) {
      this.formulario.markAllAsTouched();
      return;
    }

    const datos = this.formulario.getRawValue();
    const editando = this.rolEditando();
    const cuerpo = { name: datos.name!, permissions: datos.permissions ?? [] };

    const peticion = editando
      ? this.rolService.actualizar(editando.id, cuerpo)
      : this.rolService.crear(cuerpo);

    peticion.subscribe({
      next: () => {
        this.modalAbierto.set(false);
        this.cargar();
      },
      error: () => this.error.set('No se pudo guardar el rol.'),
    });
  }

  eliminar(rol: Rol): void {
    if (!confirm(`¿Eliminar el rol "${rol.name}"?`)) return;
    this.rolService.eliminar(rol.id).subscribe({
      next: () => this.cargar(),
      error: () => alert('Este rol no se puede eliminar.'),
    });
  }
}

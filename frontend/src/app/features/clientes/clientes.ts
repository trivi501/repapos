import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ClienteService } from '../../core/services/cliente.service';
import { AuthService } from '../../core/services/auth.service';
import { Cliente } from '../../core/models/taller.model';

@Component({
  selector: 'app-clientes',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './clientes.html',
  styleUrl: './clientes.scss',
})
export class Clientes implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly clienteService = inject(ClienteService);
  readonly authService = inject(AuthService);

  readonly clientes = signal<Cliente[]>([]);
  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);
  readonly modalAbierto = signal(false);
  readonly clienteEditando = signal<Cliente | null>(null);
  readonly pagina = signal(1);
  readonly totalPaginas = signal(1);
  readonly termino = signal('');

  readonly formulario = this.fb.group({
    nombre: ['', Validators.required],
    apellido: [''],
    telefono: ['', Validators.required],
    telefono2: [''],
    email: ['', Validators.email],
    direccion: [''],
    notas: [''],
  });

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.clienteService.listar(this.pagina(), this.termino()).subscribe({
      next: (respuesta) => {
        this.clientes.set(respuesta.data);
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
    this.clienteEditando.set(null);
    this.formulario.reset();
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  abrirEditar(cliente: Cliente): void {
    this.clienteEditando.set(cliente);
    this.formulario.reset({
      nombre: cliente.nombre,
      apellido: cliente.apellido ?? '',
      telefono: cliente.telefono,
      telefono2: cliente.telefono2 ?? '',
      email: cliente.email ?? '',
      direccion: cliente.direccion ?? '',
      notas: cliente.notas ?? '',
    });
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  cerrarModal(): void {
    this.modalAbierto.set(false);
  }

  guardar(): void {
    if (this.formulario.invalid) {
      this.formulario.markAllAsTouched();
      return;
    }

    const datos = this.formulario.getRawValue() as Partial<Cliente>;
    const editando = this.clienteEditando();
    const peticion = editando
      ? this.clienteService.actualizar(editando.id, datos)
      : this.clienteService.crear(datos);

    peticion.subscribe({
      next: () => {
        this.modalAbierto.set(false);
        this.cargar();
      },
      error: () => this.error.set('No se pudo guardar el cliente.'),
    });
  }

  eliminar(cliente: Cliente): void {
    if (!confirm(`¿Eliminar al cliente "${cliente.nombre}"?`)) return;
    this.clienteService.eliminar(cliente.id).subscribe(() => this.cargar());
  }
}

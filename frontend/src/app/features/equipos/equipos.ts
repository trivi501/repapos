import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { EquipoService } from '../../core/services/equipo.service';
import { ClienteService } from '../../core/services/cliente.service';
import { AuthService } from '../../core/services/auth.service';
import { Cliente, Equipo } from '../../core/models/taller.model';

@Component({
  selector: 'app-equipos',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './equipos.html',
  styleUrl: './equipos.scss',
})
export class Equipos implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly equipoService = inject(EquipoService);
  private readonly clienteService = inject(ClienteService);
  readonly authService = inject(AuthService);

  readonly equipos = signal<Equipo[]>([]);
  readonly clientes = signal<Cliente[]>([]);
  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);
  readonly modalAbierto = signal(false);
  readonly equipoEditando = signal<Equipo | null>(null);
  readonly pagina = signal(1);
  readonly totalPaginas = signal(1);
  readonly termino = signal('');

  readonly tipos = ['celular', 'tablet', 'laptop', 'otro'];

  readonly formulario = this.fb.group({
    cliente_id: [null as number | null, Validators.required],
    tipo: ['celular', Validators.required],
    marca: ['', Validators.required],
    modelo: ['', Validators.required],
    imei: [''],
    color: [''],
    patron_desbloqueo: [''],
    accesorios: [''],
    condicion_inicial: [''],
  });

  ngOnInit(): void {
    this.clienteService.listarTodos().subscribe((respuesta) => this.clientes.set(respuesta.data));
    this.cargar();
  }

  cargar(): void {
    this.cargando.set(true);
    this.equipoService.listar(this.pagina(), this.termino()).subscribe({
      next: (respuesta) => {
        this.equipos.set(respuesta.data);
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
    this.equipoEditando.set(null);
    this.formulario.reset({ tipo: 'celular' });
    this.error.set(null);
    this.modalAbierto.set(true);
  }

  abrirEditar(equipo: Equipo): void {
    this.equipoEditando.set(equipo);
    this.formulario.reset({
      cliente_id: equipo.cliente_id,
      tipo: equipo.tipo,
      marca: equipo.marca,
      modelo: equipo.modelo,
      imei: equipo.imei ?? '',
      color: equipo.color ?? '',
      patron_desbloqueo: equipo.patron_desbloqueo ?? '',
      accesorios: equipo.accesorios ?? '',
      condicion_inicial: equipo.condicion_inicial ?? '',
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

    const datos = this.formulario.getRawValue();
    const editando = this.equipoEditando();
    const peticion = editando
      ? this.equipoService.actualizar(editando.id, datos as Partial<Equipo>)
      : this.equipoService.crear(datos as Partial<Equipo>);

    peticion.subscribe({
      next: () => {
        this.modalAbierto.set(false);
        this.cargar();
      },
      error: () => this.error.set('No se pudo guardar el equipo.'),
    });
  }

  eliminar(equipo: Equipo): void {
    if (!confirm(`¿Eliminar el equipo "${equipo.marca} ${equipo.modelo}"?`)) return;
    this.equipoService.eliminar(equipo.id).subscribe(() => this.cargar());
  }

  nombreCliente(equipo: Equipo): string {
    if (equipo.cliente) return `${equipo.cliente.nombre} ${equipo.cliente.apellido ?? ''}`.trim();
    const cliente = this.clientes().find((c) => c.id === equipo.cliente_id);
    return cliente ? `${cliente.nombre} ${cliente.apellido ?? ''}`.trim() : '—';
  }
}

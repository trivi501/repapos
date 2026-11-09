import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { Producto } from './inventario';

@Component({
  selector: 'app-producto-form',
  imports: [FormsModule, RouterLink],
  templateUrl: './producto-form.html',
})
export class ProductoForm implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly id = signal<number | null>(null);
  readonly guardando = signal(false);
  readonly categorias = ['Pantallas', 'Baterías', 'Centros de carga', 'Tapas', 'Cámaras', 'Flex', 'Accesorios', 'Micas', 'Fundas', 'Cargadores', 'Otro'];

  m: { codigo: string; nombre: string; categoria: string; costo: number; precio: number; precio_locatario: number | null; minimo: number; existencia_inicial: number } = {
    codigo: '', nombre: '', categoria: '', costo: 0, precio: 0, precio_locatario: null, minimo: 0, existencia_inicial: 0,
  };

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) return;
    this.id.set(id);
    this.api.get<Producto>(`inventario/${id}`).subscribe({
      next: (p) => {
        this.m = {
          codigo: p.codigo, nombre: p.nombre, categoria: p.categoria, costo: Number(p.costo), precio: Number(p.precio),
          precio_locatario: p.precio_locatario !== null ? Number(p.precio_locatario) : null, minimo: p.minimo, existencia_inicial: 0,
        };
        this.id.set(id);
        this.guardando.set(false);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  guardar(): void {
    this.guardando.set(true);
    const id = this.id();
    const peticion = id ? this.api.put<{ message: string }>(`inventario/${id}`, this.m) : this.api.post<{ message: string }>('inventario', this.m);
    peticion.subscribe({
      next: (r) => this.router.navigate(['/inventario']).then(() => this.avisos.ok(r.message)),
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  eliminar(): void {
    if (!confirm(`¿Eliminar ${this.m.nombre} del catálogo? Se borran también sus existencias y movimientos en todas las sucursales.`)) return;
    this.api.delete(`inventario/${this.id()}`).subscribe({
      next: () => this.router.navigate(['/inventario']).then(() => this.avisos.ok(`Producto ${this.m.nombre} eliminado del catálogo.`)),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

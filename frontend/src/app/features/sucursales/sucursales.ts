import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';

interface Sucursal {
  id: number;
  nombre: string;
  direccion: string;
  telefono: string;
}

@Component({
  selector: 'app-sucursales',
  imports: [FormsModule, RouterLink],
  templateUrl: './sucursales.html',
})
export class Sucursales implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly sucursales = signal<Sucursal[]>([]);
  nueva = { nombre: '', direccion: '', telefono: '' };

  ngOnInit(): void {
    this.cargar();
  }

  private cargar(): void {
    this.api.get<Sucursal[]>('sucursales').subscribe({
      next: (s) => this.sucursales.set(s),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  /** El selector de sucursal del menú también debe enterarse del cambio. */
  private alTerminar(mensaje: string): void {
    this.avisos.ok(mensaje);
    this.auth.refrescar().subscribe();
    this.cargar();
  }

  crear(): void {
    this.api.post<{ message: string }>('sucursales', this.nueva).subscribe({
      next: (r) => {
        this.nueva = { nombre: '', direccion: '', telefono: '' };
        this.alTerminar(r.message);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  guardar(s: Sucursal): void {
    this.api.put<{ message: string }>(`sucursales/${s.id}`, s).subscribe({
      next: (r) => this.alTerminar(r.message),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  eliminar(s: Sucursal): void {
    if (!confirm(`¿Eliminar la sucursal ${s.nombre}?`)) return;
    this.api.delete(`sucursales/${s.id}`).subscribe({
      next: () => this.alTerminar('Sucursal eliminada.'),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

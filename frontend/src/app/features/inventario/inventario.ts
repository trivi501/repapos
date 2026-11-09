import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe } from '../../shared/ui';

export interface Producto {
  id: number;
  codigo: string;
  nombre: string;
  categoria: string;
  costo: string;
  precio: string;
  precio_locatario: string | null;
  minimo: number;
  hay: number;
}

interface Datos {
  productos: Producto[];
  categorias: string[];
  sucursales: { id: number; nombre: string }[];
  valor: number;
  bajos: number;
}

@Component({
  selector: 'app-inventario',
  imports: [FormsModule, RouterLink, DineroPipe],
  templateUrl: './inventario.html',
})
export class Inventario implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly datos = signal<Datos | null>(null);
  readonly menu = signal<{ p: Producto; x: number; y: number } | null>(null);

  filtros = { q: '', categoria: '', bajos: false };
  venta: { piezas: number; importe: number | null } = { piezas: 1, importe: null };
  entrada: { piezas: number; importe: number | null; de_caja: boolean } = { piezas: 1, importe: null, de_caja: false };
  ajuste = { piezas: 0 };
  traspaso: { piezas: number; destino: number | null } = { piezas: 1, destino: null };

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.api.get<Datos>('inventario', this.filtros).subscribe({
      next: (d) => this.datos.set(d),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  abrirMenu(evento: MouseEvent, p: Producto): void {
    evento.preventDefault();
    this.venta = { piezas: 1, importe: Number(p.precio) };
    this.entrada = { piezas: 1, importe: null, de_caja: false };
    this.ajuste = { piezas: p.hay };
    this.traspaso = { piezas: 1, destino: this.datos()?.sucursales[0]?.id ?? null };
    const x = Math.min(evento.clientX, window.innerWidth - 340);
    const y = Math.min(evento.clientY, window.innerHeight - 330);
    this.menu.set({ p, x: Math.max(8, x), y: Math.max(8, y) });
  }

  mover(p: Producto, tipo: string, datos: object): void {
    this.menu.set(null);
    this.api.post<{ message: string }>(`inventario/${p.id}/movimiento`, { tipo, ...datos }).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

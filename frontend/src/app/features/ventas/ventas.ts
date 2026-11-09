import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, fechaISO } from '../../shared/ui';

export interface DetalleVenta {
  id: number;
  nombre: string;
  cantidad: number;
  devuelto: number;
  precio: string;
  importe: string;
}

export interface Venta {
  id: number;
  folio: number;
  tipo: string;
  estatus: string;
  metodo: string;
  cliente_nombre: string;
  cliente_telefono: string;
  subtotal: string;
  descuento: string;
  total: string;
  pagado: string;
  devuelto: string;
  recibido: string | null;
  neto: number;
  saldo: number;
  cambio: number;
  ganancia?: number;
  locatario_id: number | null;
  created_at: string;
  user?: { name: string } | null;
  detalles: DetalleVenta[];
  pagos?: { id: number; fecha: string; concepto: string; metodo: string; monto: string }[];
  whatsapp?: string | null;
  pdf_publico?: string;
}

interface Datos {
  ventas: Venta[];
  desde: string;
  hasta: string;
  resumen: { ventas: number; total: number; por_cobrar: number; ganancia: number; cliente: number; locatario: number; metodos: Record<string, number> };
}

export function metodoTexto(m: string): string {
  return m === 'CRÉDITO' ? 'A crédito' : m.charAt(0) + m.slice(1).toLowerCase();
}

@Component({
  selector: 'app-ventas',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  templateUrl: './ventas.html',
})
export class Ventas implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly datos = signal<Datos | null>(null);
  readonly credito = signal(false);
  readonly metodoTexto = metodoTexto;

  desde = fechaISO();
  hasta = fechaISO();

  ngOnInit(): void {
    this.ver(false);
  }

  ver(credito: boolean): void {
    this.credito.set(credito);
    this.api.get<Datos>('ventas', { desde: this.desde, hasta: this.hasta, credito }).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.desde = d.desde;
        this.hasta = d.hasta;
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  hoy(): void {
    this.desde = this.hasta = fechaISO();
    this.ver(false);
  }

  esteMes(): void {
    const h = new Date();
    this.desde = fechaISO(new Date(h.getFullYear(), h.getMonth(), 1));
    this.hasta = fechaISO(h);
    this.ver(false);
  }

  productos(v: Venta): string {
    return v.detalles.map((d) => `${d.cantidad} x ${d.nombre}`).join(', ');
  }

  metodos(d: Datos) {
    return Object.entries(d.resumen.metodos)
      .filter(([m, monto]) => monto !== 0 || m === 'EFECTIVO')
      .map(([clave, monto]) => ({ clave, monto, texto: metodoTexto(clave) }));
  }
}

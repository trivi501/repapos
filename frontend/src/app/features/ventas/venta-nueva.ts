import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe } from '../../shared/ui';

interface ProductoCatalogo {
  id: number;
  nombre: string;
  codigo: string;
  categoria: string;
  precio: number;
  precio_locatario: number;
  existencia: number;
}

interface Linea {
  producto_id: number;
  nombre: string;
  cantidad: number;
  precio: number;
}

const redondear = (n: number) => Math.round(n * 100) / 100;

/** Punto de venta: el catálogo se trae una vez y se busca sin volver al servidor. */
@Component({
  selector: 'app-venta-nueva',
  imports: [FormsModule, RouterLink, DineroPipe],
  templateUrl: './venta-nueva.html',
})
export class VentaNueva implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  private readonly productos = signal<ProductoCatalogo[]>([]);
  readonly locatarios = signal<string[]>([]);
  readonly catalogoVacio = signal(false);
  readonly tipo = signal<'CLIENTE' | 'LOCATARIO'>('CLIENTE');
  readonly buscar = signal('');
  readonly carrito = signal<Linea[]>([]);
  readonly guardando = signal(false);

  locatario = '';
  descuento = 0;
  metodo = 'EFECTIVO';
  recibido: number | null = null;
  clienteNombre = '';
  clienteTelefono = '';

  readonly resultados = computed(() => {
    const q = this.buscar().trim().toLowerCase();
    if (!q) return [];
    return this.productos()
      .filter((p) => p.nombre.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q))
      .slice(0, 12);
  });

  readonly formas = computed(() => [
    { valor: 'EFECTIVO', texto: 'Efectivo' },
    { valor: 'TARJETA', texto: 'Tarjeta' },
    { valor: 'TRANSFERENCIA', texto: 'Transferencia' },
    // a crédito: solo a locatarios
    ...(this.tipo() === 'LOCATARIO' ? [{ valor: 'CRÉDITO', texto: 'A crédito' }] : []),
  ]);

  ngOnInit(): void {
    this.api.get<{ productos: ProductoCatalogo[]; locatarios: string[] }>('ventas/catalogo').subscribe({
      next: (r) => {
        this.productos.set(r.productos);
        this.locatarios.set(r.locatarios);
        this.catalogoVacio.set(r.productos.length === 0);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  precioDe(p: ProductoCatalogo): number {
    return this.tipo() === 'LOCATARIO' ? p.precio_locatario : p.precio;
  }

  cambiarTipo(tipo: 'CLIENTE' | 'LOCATARIO'): void {
    this.tipo.set(tipo);
    if (tipo === 'CLIENTE' && this.metodo === 'CRÉDITO') this.metodo = 'EFECTIVO';
    // cada renglón toma el precio del nuevo tipo de venta
    this.carrito.update((c) =>
      c.map((l) => {
        const p = this.productos().find((x) => x.id === l.producto_id);
        return p ? { ...l, precio: this.precioDe(p) } : l;
      }),
    );
  }

  agregar(p: ProductoCatalogo): void {
    this.carrito.update((c) => {
      const ya = c.find((l) => l.producto_id === p.id);
      return ya
        ? c.map((l) => (l === ya ? { ...l, cantidad: l.cantidad + 1 } : l))
        : [...c, { producto_id: p.id, nombre: p.nombre, cantidad: 1, precio: this.precioDe(p) }];
    });
    this.buscar.set('');
    document.getElementById('buscar')?.focus();
  }

  /** Con lector de códigos: Enter agrega el producto con ese código exacto, o el primer resultado. */
  agregarPrimero(): void {
    const q = this.buscar().trim().toLowerCase();
    const exacto = this.productos().find((p) => p.codigo && p.codigo.toLowerCase() === q);
    const p = exacto ?? this.resultados()[0];
    if (p) this.agregar(p);
  }

  quitar(l: Linea): void {
    this.carrito.update((c) => c.filter((x) => x !== l));
  }

  existencia(id: number): number {
    return this.productos().find((p) => p.id === id)?.existencia ?? 0;
  }

  excede(l: Linea): boolean {
    return Number(l.cantidad) > this.existencia(l.producto_id);
  }

  // Son métodos y no computed: cantidad, precio y descuento cambian con ngModel, fuera de los signals.
  subtotal(): number {
    return redondear(this.carrito().reduce((s, l) => s + Number(l.cantidad) * Number(l.precio), 0));
  }

  total(): number {
    return redondear(Math.max(0, this.subtotal() - Number(this.descuento || 0)));
  }

  cambio(): number {
    return this.recibido ? Math.max(0, redondear(Number(this.recibido) - this.total())) : 0;
  }

  falta(): boolean {
    return this.metodo === 'EFECTIVO' && !!this.recibido && Number(this.recibido) < this.total();
  }

  puedeCobrar(): boolean {
    if (!this.carrito().length || this.falta() || this.carrito().some((l) => this.excede(l) || Number(l.cantidad) < 1)) return false;
    return this.tipo() === 'CLIENTE' || !!this.locatario.trim();
  }

  cobrar(): void {
    this.guardando.set(true);
    this.api
      .post<{ message: string; venta: { id: number } }>('ventas', {
        lineas: this.carrito().map((l) => ({ producto_id: l.producto_id, cantidad: l.cantidad, precio: l.precio })),
        descuento: this.descuento || 0,
        metodo: this.metodo,
        tipo: this.tipo(),
        locatario: this.tipo() === 'LOCATARIO' ? this.locatario : null,
        recibido: this.metodo === 'EFECTIVO' ? this.recibido : null,
        cliente_nombre: this.tipo() === 'CLIENTE' ? this.clienteNombre : null,
        cliente_telefono: this.tipo() === 'CLIENTE' ? this.clienteTelefono || null : null,
      })
      .subscribe({
        next: (r) => this.router.navigate(['/ventas', r.venta.id]).then(() => this.avisos.ok(r.message)),
        error: (e: HttpErrorResponse) => {
          this.guardando.set(false);
          this.avisos.fallo(e);
        },
      });
  }
}

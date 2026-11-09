import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Observable } from 'rxjs';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, dinero } from '../../shared/ui';
import { Venta, metodoTexto } from './ventas';

@Component({
  selector: 'app-venta-detalle',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  templateUrl: './venta-detalle.html',
})
export class VentaDetalle implements OnInit {
  readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  readonly auth = inject(AuthService);

  readonly venta = signal<Venta | null>(null);
  readonly activa = computed(() => this.venta()?.estatus !== 'CANCELADA');
  readonly puedeDevolver = computed(() => (this.venta()?.detalles ?? []).some((d) => d.cantidad - d.devuelto > 0));
  readonly metodoTexto = metodoTexto;

  piezas: Record<number, number> = {};
  destino = 'efectivo';
  nota = '';
  montoAbono: number | null = null;
  metodoAbono = 'EFECTIVO';

  private get id(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.api.get<Venta>(`ventas/${this.id}`).subscribe({
      next: (v) => {
        this.venta.set(v);
        this.piezas = {};
        this.montoAbono = v.saldo > 0 ? v.saldo : null;
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  private ejecutar(peticion: Observable<{ message: string }>): void {
    peticion.subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  devolver(): void {
    if (!Object.values(this.piezas).some((n) => n > 0)) {
      this.avisos.fallo('Indica cuántas piezas regresa.');
      return;
    }
    if (!confirm('¿Registrar la devolución? Las piezas regresan al inventario.')) return;
    this.ejecutar(this.api.post(`ventas/${this.id}/devolver`, { piezas: this.piezas, destino: this.destino, nota: this.nota }));
  }

  abonar(): void {
    this.ejecutar(this.api.post(`ventas/${this.id}/abonar`, { monto: this.montoAbono, metodo: this.metodoAbono }));
  }

  cancelar(): void {
    const v = this.venta()!;
    const dinero_ = Number(v.pagado) > 0 ? ` y se restan ${dinero(v.pagado)} de la caja de hoy` : '';
    if (!confirm(`¿Cancelar la venta ${v.folio}? Las piezas regresan al inventario${dinero_}.`)) return;
    this.ejecutar(this.api.post(`ventas/${this.id}/cancelar`));
  }
}

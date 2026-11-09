import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, Estatus, FechaPipe, dinero } from '../../shared/ui';

interface Movimiento {
  id: number;
  fecha: string;
  tipo: string;
  concepto: string;
  monto: string;
}

interface Elegido {
  id: number;
  nombre: string;
  a_favor: number;
  deuda: number;
  movimientos: Movimiento[];
  plan: { id: number; modelo: string; monto: number; parcial: boolean }[];
  whatsapp: string | null;
}

interface Datos {
  lista: { id: number; nombre: string; a_favor: number }[];
  locatarios: string[];
  total: number;
  elegido: Elegido | null;
}

@Component({
  selector: 'app-saldos',
  imports: [FormsModule, DineroPipe, FechaPipe, Estatus],
  templateUrl: './saldos.html',
})
export class Saldos implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly datos = signal<Datos | null>(null);
  readonly todos = signal(false);
  private locatarioId: number | null = null;

  deposito: { locatario: string; monto: number | null; nota: string } = { locatario: '', monto: null, nota: '' };
  montoDevolver: number | null = null;

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.api.get<Datos>('saldos', { todos: this.todos(), locatario: this.locatarioId }).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.montoDevolver = d.elegido?.a_favor ?? null;
        if (d.elegido) this.deposito.locatario = d.elegido.nombre;
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  elegir(id: number): void {
    this.locatarioId = id;
    this.cargar();
  }

  alternarTodos(): void {
    this.todos.update((t) => !t);
    this.cargar();
  }

  sumaPlan(e: Elegido): number {
    return e.plan.reduce((s, p) => s + p.monto, 0);
  }

  private ejecutar<T extends { message: string }>(peticion: import('rxjs').Observable<T>, despues?: (r: T) => void): void {
    peticion.subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        despues?.(r);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  depositar(): void {
    this.ejecutar(this.api.post<{ message: string; locatario_id: number }>('saldos/deposito', this.deposito), (r) => {
      this.locatarioId = r.locatario_id;
      this.deposito.monto = null;
      this.deposito.nota = '';
    });
  }

  aplicar(e: Elegido): void {
    const detalle = e.plan.map((p) => `#${p.id} ${p.modelo}: ${dinero(p.monto)} ${p.parcial ? '(abono parcial)' : '(queda pagado)'}`).join('\n');
    if (!confirm(`Se usará el saldo a favor así:\n\n${detalle}\n\n¿Continuar?`)) return;
    this.ejecutar(this.api.post<{ message: string }>(`saldos/${e.id}/aplicar`));
  }

  devolver(e: Elegido): void {
    if (!confirm('¿Registrar la devolución en efectivo? Sale de la caja de hoy.')) return;
    this.ejecutar(this.api.post<{ message: string }>(`saldos/${e.id}/devolver`, { monto: this.montoDevolver }));
  }

  eliminar(m: Movimiento, locatario: number): void {
    const pregunta: Record<string, string> = {
      DEPÓSITO: '¿Eliminar este depósito? También se quita el cobro de la caja de ese día.',
      DEVUELTO: '¿Eliminar esta devolución? Se quita la salida de caja y el dinero regresa a su saldo a favor.',
    };
    if (!confirm(pregunta[m.tipo] ?? '¿Eliminar? El teléfono vuelve a deber ese monto y el dinero regresa a su saldo a favor.')) return;
    this.locatarioId = locatario;
    this.ejecutar(this.api.delete<{ message: string }>(`saldos/movimiento/${m.id}`));
  }
}

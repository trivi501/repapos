import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, dinero, fechaISO } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';

interface CorteDatos {
  dia: string;
  pagos: { id: number; fecha: string; descripcion: string; concepto: string; metodo: string; monto: string }[];
  salidas: { id: number; fecha: string; concepto: string; categoria: string; monto: string }[];
  entradas: number;
  cobros_efectivo: number;
  otros_metodos: Record<string, number>;
  total_salidas: number;
  corte: { contado: string; notas: string; updated_at: string; user?: { name: string } | null } | null;
  fondo: number;
  cambios_despues: boolean;
}

@Component({
  selector: 'app-corte',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  templateUrl: './corte.html',
})
export class Corte implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly hoy = fechaISO();
  readonly dia = signal(this.hoy);
  readonly d = signal<CorteDatos | null>(null);

  fondo = 0;
  contado = 0;
  notas = '';

  ngOnInit(): void {
    this.ir(this.route.snapshot.queryParamMap.get('fecha') ?? this.hoy);
  }

  ir(fecha: string): void {
    if (!fecha) return;
    this.dia.set(fecha);
    this.router.navigate([], { queryParams: { fecha }, replaceUrl: true });
    this.cargar();
  }

  mover(dias: number): void {
    const d = new Date(`${this.dia()}T12:00:00`);
    d.setDate(d.getDate() + dias);
    this.ir(fechaISO(d));
  }

  private cargar(): void {
    this.api.get<CorteDatos>('cortes', { fecha: this.dia() }).subscribe({
      next: (d) => {
        this.fondo = d.fondo;
        this.contado = d.corte ? Number(d.corte.contado) : 0;
        this.notas = d.corte?.notas ?? '';
        this.d.set(d);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  diaTexto(): string {
    return new Date(`${this.dia()}T12:00:00`).toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }

  hora(fecha: string): string {
    return new Date(fecha).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false });
  }

  otros(x: CorteDatos) {
    return Object.entries(x.otros_metodos).map(([metodo, monto]) => ({ metodo, monto, texto: metodoTexto(metodo) }));
  }

  esperado(): number {
    const x = this.d();
    return x ? Math.round((Number(this.fondo || 0) + x.entradas - x.total_salidas) * 100) / 100 : 0;
  }

  diferencia(): number {
    return Math.round((Number(this.contado || 0) - this.esperado()) * 100) / 100;
  }

  textoDiferencia(): string {
    const dif = this.diferencia();
    if (dif === 0) return '$0.00 ✓ Cuadra';
    return dif > 0 ? `${dinero(dif)} Sobra` : `${dinero(-dif)} Falta`;
  }

  guardar(): void {
    if (this.d()?.corte && !confirm('Este día ya tiene un corte guardado. ¿Reemplazarlo?')) return;
    this.api.post<{ message: string }>('cortes', { fecha: this.dia(), fondo: this.fondo, contado: this.contado, notas: this.notas }).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  imprimir(): void {
    window.print();
  }
}

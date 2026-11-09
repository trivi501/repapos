import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { ESTADOS_ORDEN } from '../../core/models/taller.model';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { FilaGrafica, GraficaBarras, GraficaLineas } from '../../shared/graficas';
import { DineroPipe } from '../../shared/ui';

/** Cada sección llega solo si el usuario tiene su permiso. */
interface DatosPanel {
  dias: number;
  fechas: string[];
  ingresos?: { cobrado: number[]; salidas: number[]; total_cobrado: number; total_salidas: number; metodos: FilaGrafica[] };
  ventas?: { importes: number[]; total: number; numero: number; productos: FilaGrafica[] };
  reparaciones?: { estados: { estado: string; valor: number }[]; abiertas: number; recibidas: number; entregadas: number };
  telefonos?: { recibidos: number[]; entregados: number[]; en_taller: number; listos: number; no_quedaron: number };
  cobranza?: { total: number; locatarios: FilaGrafica[] };
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterLink, DineroPipe, GraficaLineas, GraficaBarras],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly usuario = this.auth.usuarioActual;
  readonly periodos = [7, 30, 90];
  readonly periodo = signal(30);
  readonly datos = signal<DatosPanel | null>(null);
  readonly cargando = signal(true);
  readonly hayGraficas = computed(() => {
    const d = this.datos();
    return !!d && !!(d.ingresos || d.ventas || d.reparaciones || d.telefonos || d.cobranza);
  });

  private peticion?: Subscription;

  ngOnInit(): void {
    this.cargar();
  }

  elegir(dias: number): void {
    this.periodo.set(dias);
    this.cargar();
  }

  /** Mientras llegan los datos nuevos, las gráficas anteriores siguen en su lugar. */
  cargar(): void {
    this.cargando.set(true);
    this.peticion?.unsubscribe();
    this.peticion = this.api.get<DatosPanel>('panel', { dias: this.periodo() }).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.cargando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.cargando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  hayValores(valores: number[]): boolean {
    return valores.some((v) => v !== 0);
  }

  filasEstados(estados: { estado: string; valor: number }[]): FilaGrafica[] {
    return estados.map((e) => ({
      nombre: ESTADOS_ORDEN.find((o) => o.valor === e.estado)?.etiqueta ?? e.estado,
      valor: e.valor,
    }));
  }
}

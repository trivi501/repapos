import { Location } from '@angular/common';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { environment } from '../../../environments/environment';
import { ESTADOS_LIBERACION, ESTADOS_ORDEN } from '../../core/models/taller.model';
import { AppLogo } from '../../shared/ui';

interface ResultadoConsulta {
  folio: string;
  estado: string;
  verificado: boolean;
  equipo?: string;
  motivo?: string;
  fechas?: { recepcion: string; estimado_listo: string | null; entrega: string | null };
  diagnostico?: string | null;
  solucion?: string | null;
  notas_cliente?: string | null;
  costos?: { total: number; anticipo: number; saldo: number };
  fotos_antes?: string[];
  fotos_proceso?: string[];
  fotos_despues?: string[];
}

/** Página pública (sin inicio de sesión) donde el cliente consulta su reparación por folio. */
@Component({
  selector: 'app-consulta',
  standalone: true,
  imports: [ReactiveFormsModule, AppLogo],
  templateUrl: './consulta.html',
  styleUrl: './consulta.scss',
})
export class Consulta implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);

  readonly cargando = signal(false);
  readonly error = signal<string | null>(null);
  readonly resultado = signal<ResultadoConsulta | null>(null);

  readonly formulario = this.fb.group({
    folio: ['', Validators.required],
    telefono: ['', Validators.pattern(/^\d{4}$/)],
  });

  ngOnInit(): void {
    const folio = this.route.snapshot.paramMap.get('folio');
    if (folio) {
      this.formulario.controls.folio.setValue(folio);
      this.consultar();
    }
  }

  consultar(): void {
    if (this.formulario.invalid) {
      this.error.set(
        this.formulario.controls.folio.invalid ? 'Ingresa el folio de tu ticket.' : 'Ingresa exactamente 4 dígitos del teléfono.',
      );
      return;
    }

    const folio = this.formulario.controls.folio.value!.trim().toUpperCase();
    const telefono = this.formulario.controls.telefono.value ?? '';
    this.cargando.set(true);
    this.error.set(null);
    // Solo actualiza la URL (compartible); navegar con el router recrearía el componente.
    this.location.replaceState(`/consulta/${encodeURIComponent(folio)}`);

    let params = new HttpParams();
    if (telefono) params = params.set('telefono', telefono);

    this.http.get<ResultadoConsulta>(`${environment.apiUrl}/consulta/${encodeURIComponent(folio)}`, { params }).subscribe({
      next: (r) => {
        this.resultado.set(r);
        this.cargando.set(false);
        if (telefono && !r.verificado) this.error.set('Los dígitos no coinciden con el teléfono registrado.');
      },
      error: (e: HttpErrorResponse) => {
        this.resultado.set(null);
        this.cargando.set(false);
        this.error.set(
          e.status === 429 ? 'Demasiados intentos. Espera un minuto.' : (e.error?.message ?? 'No se pudo realizar la consulta.'),
        );
      },
    });
  }

  gruposFotos(r: ResultadoConsulta): { titulo: string; urls: string[] }[] {
    return [
      { titulo: 'Fotos de antes', urls: r.fotos_antes ?? [] },
      { titulo: 'Evidencias del proceso', urls: r.fotos_proceso ?? [] },
      { titulo: 'Fotos de después', urls: r.fotos_despues ?? [] },
    ].filter((g) => g.urls.length);
  }

  etiquetaEstado(estado: string): string {
    // el cliente ve "En proceso" / "Rechazado", sin la aclaración "(liberación)" de los filtros internos
    return [...ESTADOS_LIBERACION, ...ESTADOS_ORDEN].find((e) => e.valor === estado)?.etiqueta ?? estado;
  }

  claseEstado(estado: string): string {
    if (estado === 'entregado' || estado === 'reparado') return 'badge-success';
    if (estado === 'cancelado' || estado === 'no_quedo' || estado === 'rechazado') return 'badge-danger';
    if (estado === 'esperando_repuesto') return 'badge-warning';
    return 'badge-primary';
  }

  fecha(valor: string): string {
    return new Date(valor).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
  }
}

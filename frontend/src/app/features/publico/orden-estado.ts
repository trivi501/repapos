import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { DineroPipe, Estatus, FechaPipe } from '../../shared/ui';

interface EstadoPublico {
  numero: number;
  estado: string;
  estado_texto: string;
  taller: string;
  logo_url: string | null;
  sucursal: string;
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
  enlace_pdf?: string;
  terminos?: string;
}

const PASOS = [
  { estados: ['recibido'], texto: 'Recibido' },
  { estados: ['diagnostico'], texto: 'Diagnóstico' },
  { estados: ['en_reparacion', 'esperando_repuesto', 'en_proceso'], texto: 'En reparación' },
  { estados: ['reparado', 'no_quedo', 'rechazado'], texto: 'Listo' },
  { estados: ['entregado'], texto: 'Entregado' },
];

/** Página a la que llega el cliente con el enlace de su comprobante o de WhatsApp (sin iniciar sesión). */
@Component({
  selector: 'app-orden-estado',
  imports: [RouterLink, DineroPipe, FechaPipe, Estatus],
  templateUrl: './orden-estado.html',
})
export class OrdenEstado implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  readonly d = signal<EstadoPublico | null>(null);
  readonly noExiste = signal(false);

  ngOnInit(): void {
    this.api.get<EstadoPublico>(`publico/orden/${encodeURIComponent(this.route.snapshot.paramMap.get('token') ?? '')}`).subscribe({
      next: (d) => this.d.set(d),
      error: () => this.noExiste.set(true),
    });
  }

  pasos(o: EstadoPublico) {
    const actual = PASOS.findIndex((p) => p.estados.includes(o.estado));
    return PASOS.map((p, i) => ({
      texto: p.texto === 'Listo' && o.estado === 'no_quedo' ? 'No se pudo reparar' : p.texto,
      hecho: actual >= 0 && i <= actual,
    }));
  }

  gruposFotos(o: EstadoPublico) {
    return [
      { titulo: 'Fotos de antes', urls: o.fotos_antes ?? [] },
      { titulo: 'Evidencias del proceso', urls: o.fotos_proceso ?? [] },
      { titulo: 'Fotos de después', urls: o.fotos_despues ?? [] },
    ].filter((g) => g.urls.length);
  }

  terminos(o: EstadoPublico): string[] {
    return (o.terminos ?? '').split('\n').map((t) => t.trim()).filter(Boolean);
  }
}

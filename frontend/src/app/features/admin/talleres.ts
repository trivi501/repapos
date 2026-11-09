import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { FechaPipe } from '../../shared/ui';

interface TallerFila {
  id: number;
  nombre: string;
  whatsapp: string | null;
  notas: string;
  estado: string;
  estado_texto: string;
  vigente: boolean;
  admin: { name: string; email: string } | null;
  usuarios_count: number;
  telefonos_count: number;
  ultimo_uso: string | null;
  created_at: string;
}

interface Datos {
  talleres: TallerFila[];
  resumen: { total: number; pagando: number; en_prueba: number; vencidos: number };
}

@Component({
  selector: 'app-talleres',
  imports: [FormsModule, FechaPipe],
  templateUrl: './talleres.html',
})
export class Talleres implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);

  readonly d = signal<Datos | null>(null);
  q = '';
  meses: Record<number, number> = {};

  ngOnInit(): void {
    this.cargar();
  }

  cargar(): void {
    this.api.get<Datos>('admin/talleres', { q: this.q }).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  actuar(t: TallerFila, accion: string, extra: object = {}): void {
    this.api.patch<{ message: string }>(`admin/talleres/${t.id}`, { accion, ...extra }).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  suspender(t: TallerFila): void {
    if (confirm(`¿Suspender ${t.nombre}? No podrá entrar, pero sus datos se conservan.`)) this.actuar(t, 'suspender');
  }

  cortesia(t: TallerFila): void {
    if (confirm(`¿Dar acceso gratis sin vencimiento a ${t.nombre}?`)) this.actuar(t, 'cortesia');
  }
}

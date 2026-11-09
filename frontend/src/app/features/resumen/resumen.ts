import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe } from '../../shared/ui';

interface Fila {
  locatario: { id: number; nombre: string; whatsapp: string | null };
  telefonos: number;
  en_taller: number;
  listos: number;
  no_quedaron: number;
  total: number;
  pagado: number;
  debe: number;
  a_favor: number;
  entregados_deben: number;
  cuenta_url: string | null;
}

@Component({
  selector: 'app-resumen',
  imports: [RouterLink, DineroPipe],
  templateUrl: './resumen.html',
})
export class Resumen implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);

  readonly filas = signal<Fila[]>([]);
  readonly cargando = signal(true);

  ngOnInit(): void {
    this.api.get<Fila[]>('resumen').subscribe({
      next: (f) => {
        this.filas.set(f);
        this.cargando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.cargando.set(false);
        this.avisos.fallo(e);
      },
    });
  }

  suma(campo: 'total' | 'pagado' | 'debe' | 'a_favor'): number {
    return this.filas().reduce((s, f) => s + Number(f[campo]), 0);
  }
}

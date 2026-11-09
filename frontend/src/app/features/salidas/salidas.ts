import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, dinero, fechaHoraLocal, fechaISO } from '../../shared/ui';

interface Salida {
  id: number;
  fecha: string;
  concepto: string;
  categoria: string;
  monto: string;
  notas: string;
}

function vacia() {
  return { concepto: '', monto: null as number | null, fecha: fechaHoraLocal(), categoria: '', notas: '' };
}

@Component({
  selector: 'app-salidas',
  imports: [FormsModule, DineroPipe, FechaPipe],
  templateUrl: './salidas.html',
})
export class Salidas implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  readonly auth = inject(AuthService);

  readonly salidas = signal<Salida[]>([]);
  readonly categorias = signal<string[]>([]);
  readonly editando = signal<number | null>(null);
  readonly total = computed(() => this.salidas().reduce((s, x) => s + Number(x.monto), 0));

  m = vacia();
  desde = fechaISO();
  hasta = fechaISO();

  ngOnInit(): void {
    this.desde = this.route.snapshot.queryParamMap.get('desde') ?? this.desde;
    this.hasta = this.route.snapshot.queryParamMap.get('hasta') ?? this.hasta;
    this.cargar();
  }

  cargar(): void {
    this.api.get<{ salidas: Salida[]; categorias: string[]; desde: string; hasta: string }>('salidas', { desde: this.desde, hasta: this.hasta }).subscribe({
      next: (r) => {
        this.desde = r.desde;
        this.hasta = r.hasta;
        this.categorias.set(r.categorias);
        this.salidas.set(r.salidas);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  hoy(): void {
    this.desde = this.hasta = fechaISO();
    this.cargar();
  }

  esteMes(): void {
    const h = new Date();
    this.desde = fechaISO(new Date(h.getFullYear(), h.getMonth(), 1));
    this.hasta = fechaISO(h);
    this.cargar();
  }

  editar(s: Salida): void {
    this.m = { concepto: s.concepto, monto: Number(s.monto), fecha: fechaHoraLocal(new Date(s.fecha)), categoria: s.categoria, notas: s.notas };
    this.editando.set(s.id);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  cancelarEdicion(): void {
    this.m = vacia();
    this.editando.set(null);
  }

  guardar(): void {
    const id = this.editando();
    const peticion = id ? this.api.put<{ message: string }>(`salidas/${id}`, this.m) : this.api.post<{ message: string }>('salidas', this.m);
    peticion.subscribe({
      next: (r) => {
        // que se vea la salida aunque su fecha quede fuera de las que se estaban viendo
        const dia = this.m.fecha.slice(0, 10);
        this.desde = dia < this.desde ? dia : this.desde;
        this.hasta = dia > this.hasta ? dia : this.hasta;
        this.avisos.ok(r.message);
        this.cancelarEdicion();
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  eliminar(s: Salida): void {
    if (!confirm(`¿Eliminar la salida ${s.concepto} de ${dinero(s.monto)}?`)) return;
    this.api.delete(`salidas/${s.id}`).subscribe({
      next: () => {
        this.avisos.ok('Salida eliminada.');
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe, FechaPipe, fechaISO } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';
import { CorteDatos, diaLargo, esperado, hora, horario, textoDiferencia, textoWhatsApp } from './corte-datos';

@Component({
  selector: 'app-corte',
  imports: [FormsModule, RouterLink, DineroPipe, FechaPipe],
  templateUrl: './corte.html',
  styles: `
    .turnos { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
    .turnos .btn { display: inline-flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
    .turnos small { font-weight: 400; opacity: 0.8; }
    .tabla-desglose td, .tabla-desglose th { white-space: nowrap; }
    .tabla-desglose tfoot td { font-weight: 700; border-top: 2px solid var(--color-border); }
    .ganancia-total { font-size: 1.4rem; font-weight: 700; }
  `,
})
export class Corte implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly hoy = fechaISO();
  readonly dia = signal(this.hoy);
  /** null = el turno abierto (o el último, si el día ya se cerró). */
  readonly turno = signal<number | null>(null);
  readonly d = signal<CorteDatos | null>(null);

  readonly hora = hora;
  readonly horario = horario;

  fondo = 0;
  contado = 0;
  retiro = 0;
  /** Si el usuario escribió el retiro, ya no se recalcula solo. */
  retiroEscrito = false;
  notas = '';

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    this.turno.set(Number(q.get('turno')) || null);
    this.ir(q.get('fecha') ?? this.hoy, this.turno());
  }

  ir(fecha: string, turno: number | null = null): void {
    if (!fecha) return;
    this.dia.set(fecha);
    this.turno.set(turno);
    this.router.navigate([], { queryParams: { fecha, turno }, replaceUrl: true });
    this.cargar();
  }

  mover(dias: number): void {
    const d = new Date(`${this.dia()}T12:00:00`);
    d.setDate(d.getDate() + dias);
    this.ir(fechaISO(d));
  }

  verTurno(turno: number | null): void {
    this.ir(this.dia(), turno);
  }

  private cargar(): void {
    this.api.get<CorteDatos>('cortes', { fecha: this.dia(), turno: this.turno() ?? '' }).subscribe({
      next: (d) => {
        this.fondo = d.fondo;
        this.contado = d.corte ? Number(d.corte.contado) : 0;
        this.retiro = d.corte ? Number(d.corte.retiro) : 0;
        this.retiroEscrito = !!d.corte;
        this.notas = d.corte?.notas ?? '';
        this.d.set(d);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  diaTexto(): string {
    return diaLargo(this.dia());
  }

  otros(x: CorteDatos) {
    return Object.entries(x.otros_metodos).map(([metodo, monto]) => ({ metodo, monto, texto: metodoTexto(metodo) }));
  }

  totalDesglose(x: CorteDatos) {
    const suma = (campo: 'EFECTIVO' | 'TARJETA' | 'TRANSFERENCIA' | 'total') => x.desglose.reduce((s, a) => s + a[campo], 0);
    return { EFECTIVO: suma('EFECTIVO'), TARJETA: suma('TARJETA'), TRANSFERENCIA: suma('TRANSFERENCIA'), total: suma('total') };
  }

  esperado(): number {
    const x = this.d();
    return x ? esperado(x, this.fondo) : 0;
  }

  diferencia(): number {
    return Math.round((Number(this.contado || 0) - this.esperado()) * 100) / 100;
  }

  textoDiferencia(): string {
    return textoDiferencia(this.diferencia());
  }

  /** Por defecto se deja el mismo fondo y se retira lo demás. */
  alCambiarCaja(): void {
    if (!this.retiroEscrito) this.retiro = Math.max(0, Math.round((Number(this.contado || 0) - Number(this.fondo || 0)) * 100) / 100);
  }

  quedaDeFondo(): number {
    return Math.round((Number(this.contado || 0) - Number(this.retiro || 0)) * 100) / 100;
  }

  guardar(): void {
    const x = this.d();
    if (!x) return;
    if (this.retiro > this.contado) {
      this.avisos.fallo('No puedes retirar más de lo que contaste.');
      return;
    }
    const pregunta = x.abierto
      ? `¿Cerrar el turno ${x.turno}? Lo que se cobre después entra al turno ${x.turno + 1}.`
      : `¿Reemplazar el corte del turno ${x.turno}?`;
    if (!confirm(pregunta)) return;
    this.api
      .post<{ message: string; corte: { turno: number } }>('cortes', {
        fecha: this.dia(), turno: x.turno, fondo: this.fondo, contado: this.contado, retiro: this.retiro, notas: this.notas,
      })
      .subscribe({
        next: (r) => {
          this.avisos.ok(r.message);
          // se queda viendo el turno que acaba de guardar
          this.ir(this.dia(), r.corte.turno);
        },
        error: (e: HttpErrorResponse) => this.avisos.fallo(e),
      });
  }

  whatsapp(): void {
    const x = this.d();
    if (!x) return;
    const texto = textoWhatsApp(x, { fondo: this.fondo, contado: this.contado, retiro: this.retiro }, this.auth.usuarioActual()?.taller?.nombre ?? '');
    window.open(`https://wa.me/?text=${encodeURIComponent(texto)}`, '_blank', 'noopener');
  }

  ticket(): void {
    const x = this.d();
    if (!x) return;
    const url = this.router.serializeUrl(this.router.createUrlTree(['/corte/ticket'], { queryParams: { fecha: x.dia, turno: x.abierto ? null : x.turno } }));
    window.open(url, '_blank');
  }

  imprimir(): void {
    window.print();
  }
}

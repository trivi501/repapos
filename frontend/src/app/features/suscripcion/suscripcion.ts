import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ApiService } from '../../core/services/api.service';
import { AvisosService } from '../../core/services/avisos.service';
import { DineroPipe } from '../../shared/ui';

interface Datos {
  taller: { id: number; nombre: string };
  vigente: boolean;
  estado_texto: string;
  dias_restantes: number | null;
  precio_mensual: number;
  precio_anual: number;
  moneda: string;
  datos_pago: string;
  contacto: string | null;
}

@Component({
  selector: 'app-suscripcion',
  imports: [DineroPipe],
  template: `
    @if (d(); as x) {
      <div class="encabezado"><h1>Suscripción de {{ x.taller.nombre }}</h1></div>
      <div class="rejilla-2 ancho-5xl alinear-arriba">
        <div class="card contenido">
          <h2>Estado de tu cuenta</h2>
          <p class="muy-grande" [class.a-favor]="x.vigente" [class.debe]="!x.vigente">{{ x.estado_texto }}</p>
          @if (x.vigente && x.dias_restantes !== null) {
            <p class="tenue">Te quedan {{ x.dias_restantes }} día(s).</p>
          }
          @if (!x.vigente) {
            <p>Tu acceso está pausado. <b>Tus datos siguen guardados</b>: en cuanto se active tu plan, todo vuelve a estar como lo dejaste.</p>
          }
          <p class="ayuda">Número de cuenta: #{{ x.taller.id }}</p>
        </div>
        <div class="card contenido">
          <h2>Activar o renovar tu plan</h2>
          <div class="rejilla-2 separado-abajo">
            <div class="plan"><span>Mensual</span><strong>{{ x.precio_mensual | dinero }}</strong></div>
            <div class="plan destacado"><span>Anual</span><strong>{{ x.precio_anual | dinero }}</strong></div>
          </div>
          <p class="etiqueta-campo">Cómo pagar:</p>
          <p class="texto-renglones">{{ x.datos_pago }}</p>
          @if (x.contacto) {
            <a class="btn btn-whatsapp" [href]="x.contacto" target="_blank" rel="noopener">Escribir por WhatsApp para activar</a>
          }
          <p class="ayuda">Al confirmar tu pago activamos tu plan y te avisamos.</p>
        </div>
      </div>
    }
  `,
})
export class Suscripcion implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly d = signal<Datos | null>(null);

  ngOnInit(): void {
    this.api.get<Datos>('suscripcion').subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

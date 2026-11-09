import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';
import { AppLogo, DineroPipe, Estatus } from '../../shared/ui';

export interface Producto {
  producto: string;
  dias_prueba: number;
  precio_mensual: number;
  precio_anual: number;
  moneda: string;
  whatsapp: string;
}

/** Página de venta para visitantes (quien ya inició sesión va directo a su pantalla de trabajo). */
@Component({
  selector: 'app-inicio',
  imports: [RouterLink, AppLogo, DineroPipe, Estatus],
  templateUrl: './inicio.html',
})
export class Inicio implements OnInit {
  private readonly api = inject(ApiService);

  readonly p = signal<Producto | null>(null);
  readonly anio = new Date().getFullYear();

  readonly muestra = [
    ['MOTO G60', 'FRP', 'LISTO', 'DEBE'],
    ['IPHONE 11', 'PANTALLA', 'EN TALLER', 'DEBE'],
    ['A15', 'LIBERACIÓN', 'LISTO', 'PAGADO'],
    ['REDMI 13', 'CENTRO DE CARGA', 'NO QUEDÓ', 'NO SE COBRA'],
  ];

  readonly funciones = [
    ['📱', 'Control de equipos', 'Da de alta cada teléfono con modelo, trabajo, IMEI, prioridad y montos. Filtra por estatus: en taller, listo, no quedó, entregado.'],
    ['💬', 'Avisos por WhatsApp', 'Al marcar un equipo como listo, el mensaje al cliente ya va escrito con su saldo. Solo presionas Enviar.'],
    ['💵', 'Cobros y abonos', 'Anticipos, abonos y liquidaciones quedan registrados con fecha y hora. Si no quedó, no se cobra.'],
    ['🧾', 'Corte de caja diario', 'Entradas, salidas y fondo: el sistema calcula cuánto debe haber en caja y te dice si sobra o falta.'],
    ['🤝', 'Cuentas por cliente', 'Resumen por cliente y envío de su estado de cuenta por WhatsApp con los equipos que debe.'],
    ['💰', 'Saldos a favor', 'Anticipos y pagos de más quedan como saldo a favor y se aplican a sus siguientes equipos.'],
    ['👥', 'Varios usuarios', 'Tú y tus empleados, cada quien con su usuario. Tú decides quién es administrador.'],
    ['☁️', 'En la nube y respaldado', 'Funciona en la PC y en el celular, sin instalar nada. Tus datos se respaldan todos los días.'],
  ];

  readonly preguntas = [
    ['¿Necesito instalar algo?', 'No. Entras desde el navegador de tu PC o de tu celular con tu correo y contraseña.'],
    ['¿Cómo funcionan los avisos por WhatsApp?', 'Al marcar un equipo como listo, se abre tu WhatsApp con el mensaje ya escrito para tu cliente. Usas tu mismo número; solo presionas Enviar.'],
    ['¿Qué pasa al terminar la prueba?', 'Te avisamos y puedes activar tu plan. Si no lo activas, tu cuenta se pausa pero tus datos se guardan.'],
    ['¿Mis datos están seguros?', 'Cada taller solo ve su propia información, la conexión va cifrada y se hace un respaldo cada día.'],
  ];

  ngOnInit(): void {
    this.api.get<Producto>('producto').subscribe((p) => this.p.set(p));
  }

  /** Con <base href> un enlace "#algo" iría a la raíz: se desplaza a mano. */
  irA(e: Event, id: string): void {
    e.preventDefault();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
  }

  contacto(): string {
    const p = this.p();
    return `https://wa.me/${(p?.whatsapp ?? '').replace(/\D/g, '')}?text=${encodeURIComponent('Hola, quiero información de ' + p?.producto)}`;
  }
}

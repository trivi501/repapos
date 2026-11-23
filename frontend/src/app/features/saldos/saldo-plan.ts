import { dinero } from '../../shared/ui';

/** Cómo se reparte el saldo a favor: primero lo entregado que debe, luego lo demás; cada grupo del más antiguo al más nuevo. */
export interface PlanSaldo {
  tipo: 'telefono' | 'venta';
  id: number;
  descripcion: string;
  fecha: string;
  debe: number;
  /** Teléfono entregado o venta a crédito: se paga primero. */
  entregado: boolean;
  monto: number;
  parcial: boolean;
}

export interface Movimiento {
  id: number;
  fecha: string;
  tipo: string;
  concepto: string;
  monto: string;
}

/** Locatario elegido en Saldos a favor (lo usa también Teléfonos para el botón de pagar con saldo). */
export interface Elegido {
  id: number;
  nombre: string;
  a_favor: number;
  deuda: number;
  movimientos: Movimiento[];
  plan: PlanSaldo[];
  whatsapp: string | null;
}

/** El detalle para la ventana de confirmación. */
export function textoPlan(plan: PlanSaldo[]): string {
  return plan
    .map((p) => `• ${p.descripcion}: ${dinero(p.monto)} ${p.parcial ? `(abono, sigue debiendo ${dinero(p.debe - p.monto)})` : '(queda liquidado)'}`)
    .join('\n');
}

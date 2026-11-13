import { dinero } from '../../shared/ui';
import { metodoTexto } from '../ventas/ventas';

/** Lo que devuelve GET /cortes: un turno (guardado o el que sigue abierto) con todo su detalle. */
export interface CorteDatos {
  dia: string;
  turno: number;
  /** El turno todavía no se cierra. */
  abierto: boolean;
  /** Queda un turno sin cerrar en el día. */
  hay_abierto: boolean;
  desde: string;
  hasta: string;
  turnos: { turno: number; desde: string; hasta: string; contado: number; diferencia: number; retiro: number; user: string | null }[];
  pagos: { id: number; fecha: string; descripcion: string; concepto: string; metodo: string; monto: string }[];
  salidas: { id: number; fecha: string; concepto: string; categoria: string; monto: string }[];
  entradas: number;
  cobros_efectivo: number;
  otros_metodos: Record<string, number>;
  total_salidas: number;
  desglose: { area: string; texto: string; cobros: number; EFECTIVO: number; TARJETA: number; TRANSFERENCIA: number; total: number }[];
  por_empleado: { nombre: string; cobros: number; efectivo: number; otros: number; salidas: number }[];
  /** Solo llega al dueño (permiso ordenes.ver_costos). */
  ganancia?: {
    reparaciones: { cantidad: number; cobrado: number; refaccion: number; mano_obra: number; ganancia: number; sin_costos: number };
    ventas: { cantidad: number; vendido: number; costo: number; ganancia: number };
    telefonos: { cantidad: number; cobrado: number; gasto: number; ganancia: number; sin_gasto: number };
    total: number;
  };
  corte: {
    contado: string;
    retiro: string;
    fondo_siguiente: string | null;
    esperado: string;
    diferencia: string;
    notas: string;
    updated_at: string;
    user?: { name: string } | null;
  } | null;
  fondo: number;
  cambios_despues: boolean;
}

export function hora(fecha: string): string {
  return new Date(fecha).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false });
}

export function diaLargo(dia: string): string {
  return new Date(`${dia}T12:00:00`).toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** "08:00 a 15:30" (el fin del día se muestra como 24:00). */
export function horario(x: { desde: string; hasta: string }, abierto = false): string {
  const fin = abierto ? 'ahora' : hora(x.hasta) === '00:00' ? '24:00' : hora(x.hasta);
  return `${hora(x.desde)} a ${fin}`;
}

/** Cuadre del turno con los valores que se capturaron en pantalla. */
export interface Cuadre {
  fondo: number;
  contado: number;
  retiro: number;
}

export function esperado(x: CorteDatos, fondo: number): number {
  return Math.round((Number(fondo || 0) + x.entradas - x.total_salidas) * 100) / 100;
}

export function textoDiferencia(dif: number): string {
  if (dif === 0) return '$0.00 ✓ Cuadra';
  return dif > 0 ? `${dinero(dif)} Sobra` : `${dinero(-dif)} Falta`;
}

/** Resumen del corte para mandarlo por WhatsApp. */
export function textoWhatsApp(x: CorteDatos, c: Cuadre, taller: string): string {
  const deberia = esperado(x, c.fondo);
  const dif = Math.round((c.contado - deberia) * 100) / 100;
  const l: string[] = [
    `*Corte de caja${taller ? ' - ' + taller : ''}*`,
    `${diaLargo(x.dia)} · Turno ${x.turno} (${horario(x, x.abierto)})`,
    '',
    '*Cobros*',
  ];
  for (const a of x.desglose) l.push(`• ${a.texto}: ${dinero(a.total)}`);
  if (!x.desglose.length) l.push('• Sin cobros');
  l.push(`Efectivo: ${dinero(x.entradas)}`);
  for (const [metodo, monto] of Object.entries(x.otros_metodos)) l.push(`${metodoTexto(metodo)}: ${dinero(monto)} (no está en caja)`);
  l.push(
    '',
    '*Caja*',
    `Fondo: ${dinero(c.fondo)}`,
    `+ Efectivo: ${dinero(x.entradas)}`,
    `- Salidas: ${dinero(x.total_salidas)}`,
    `= Debe haber: ${dinero(deberia)}`,
    `Contado: ${dinero(c.contado)}`,
    `*${textoDiferencia(dif)}*`,
    `Retiro: ${dinero(c.retiro)}`,
    `Queda de fondo: ${dinero(c.contado - c.retiro)}`,
  );
  if (x.por_empleado.length > 1) {
    l.push('', '*Por empleado*');
    for (const e of x.por_empleado) l.push(`• ${e.nombre}: ${dinero(e.efectivo + e.otros)} (${e.cobros} cobros)`);
  }
  if (x.ganancia) {
    l.push('', `*Ganancia: ${dinero(x.ganancia.total)}*`);
    l.push(`Reparaciones (${x.ganancia.reparaciones.cantidad}): ${dinero(x.ganancia.reparaciones.ganancia)}`);
    l.push(`Ventas (${x.ganancia.ventas.cantidad}): ${dinero(x.ganancia.ventas.ganancia)}`);
    l.push(`Teléfonos entregados (${x.ganancia.telefonos.cantidad}): ${dinero(x.ganancia.telefonos.ganancia)}`);
  }
  return l.join('\n');
}

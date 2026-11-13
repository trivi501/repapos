export interface Paginado<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface Cliente {
  id: number;
  nombre: string;
  apellido?: string | null;
  telefono: string;
  telefono2?: string;
  email?: string | null;
  direccion?: string | null;
  notas?: string | null;
  created_at?: string;
}

export interface Equipo {
  id: number;
  cliente_id: number;
  cliente?: Cliente;
  tipo: string;
  marca: string;
  modelo: string;
  imei?: string | null;
  color?: string | null;
  patron_desbloqueo?: string | null;
  accesorios?: string | null;
  condicion_inicial?: string | null;
}

export type EstadoOrden =
  | 'recibido'
  | 'diagnostico'
  | 'en_reparacion'
  | 'esperando_repuesto'
  | 'reparado'
  | 'no_quedo'
  | 'entregado'
  | 'cancelado'
  // solo liberaciones
  | 'en_proceso'
  | 'rechazado';

export interface OrdenReparacion {
  id: number;
  numero_orden: string;
  folio?: number;
  token?: string;
  cliente_id: number;
  cliente?: Cliente;
  equipo_id: number;
  equipo?: Equipo;
  tecnico_id?: number | null;
  tecnico?: { id: number; name: string } | null;
  sucursal_id?: number | null;
  estado: EstadoOrden;
  prioridad: 'baja' | 'media' | 'alta';
  tipo_servicio?: string;
  bloqueo_tipo?: string;
  bloqueo_valor?: string | null;
  bloqueo_pin?: string | null;
  bloqueos?: string[] | null;
  /** Trae algún bloqueo (operador, FRP, iCloud, MDM, Knox): solo tiene los 4 estatus de liberación. */
  es_liberacion?: boolean;
  motivo_rechazo?: string | null;
  /** Costos internos y ganancia: solo llegan si el usuario tiene "ordenes.ver_costos". */
  costo_refaccion?: string | null;
  costo_mano_obra?: string | null;
  /** Lo que se paga a otro taller o técnico cuando el equipo se manda fuera, y a quién. */
  costo_mano_externa?: string | null;
  mano_externa_detalle?: string | null;
  ganancia?: number;
  /** Refacciones usadas: del inventario o compradas fuera. Su suma es el costo de refacción. */
  refacciones?: RefaccionOrden[];
  /** Veces que el cliente abrió su enlace o buscó su folio, y la última vez. */
  consultas_cliente?: number;
  ultima_consulta_at?: string | null;
  estado_fisico?: Record<string, string> | null;
  revision?: Record<string, string> | null;
  observaciones?: string | null;
  total?: number;
  saldo?: number;
  pagado?: string;
  pagos?: { id: number; fecha: string; concepto: string; metodo: string; monto: string }[];
  anticipo?: string | number | null;
  metodo_pago?: string;
  estimado_listo_at?: string | null;
  falla_reportada: string;
  diagnostico?: string | null;
  solucion?: string | null;
  costo_estimado?: string | null;
  costo_final?: string | null;
  fecha_recepcion?: string;
  fecha_entrega_estimada?: string | null;
  fecha_entrega_real?: string | null;
  notas?: string | null;
  notas_cliente?: string | null;
  visible_cliente?: boolean;
  visibilidad?: string[] | null;
  fotos?: FotoOrden[];
}

export interface RefaccionOrden {
  id: number;
  producto_id: number | null;
  descripcion: string;
  cantidad: number;
  del_inventario: boolean;
  /** Solo llegan con el permiso "ordenes.ver_costos". */
  costo_unitario?: string;
  importe?: number;
}

/** Producto para elegir como refacción (el costo solo llega con "ordenes.ver_costos"). */
export interface ProductoRefaccion {
  id: number;
  nombre: string;
  codigo: string | null;
  categoria: string | null;
  hay: number;
  costo?: number;
}

export type CategoriaFoto = 'antes' | 'proceso' | 'despues';

export interface FotoOrden {
  id: number;
  orden_reparacion_id: number | null;
  categoria: CategoriaFoto;
  nombre_original: string | null;
  tamano: number;
  url: string;
}

export interface Rol {
  id: number;
  name: string;
  permissions?: Permiso[];
}

export interface Permiso {
  id: number;
  name: string;
}

export interface UsuarioAdmin {
  id: number;
  name: string;
  email: string;
  roles: Rol[];
  sucursal_id?: number | null;
  sucursal?: { id: number; nombre: string } | null;
  /** Gráficas del panel asignadas a esta persona (las de su rol van aparte). */
  panel?: string[];
}

/** Cada gráfica del panel tiene su permiso. */
export const GRAFICAS_PANEL: { permiso: string; nombre: string }[] = [
  { permiso: 'panel.ingresos', nombre: 'Cobros y salidas' },
  { permiso: 'panel.ventas', nombre: 'Ventas de mostrador' },
  { permiso: 'panel.telefonos', nombre: 'Teléfonos de locatarios' },
  { permiso: 'panel.reparaciones', nombre: 'Reparaciones' },
  { permiso: 'panel.cobranza', nombre: 'Por cobrar a locatarios' },
];

/** Las que se marcan de inicio en un usuario nuevo: no muestran dinero. */
export const GRAFICAS_BASICAS = ['panel.reparaciones', 'panel.telefonos'];

export interface Sucursal {
  id: number;
  nombre: string;
}

/** Revisión al recibir el equipo: cada punto se marca Sí o No (igual que la nota de recepción en papel). */
export const REVISION_ORDEN = [
  { clave: 'bateria', texto: 'Batería' },
  { clave: 'chip', texto: 'Chip' },
  { clave: 'memoria', texto: 'Memoria' },
  { clave: 'cargador', texto: 'Cargador' },
  { clave: 'senal', texto: 'Señal' },
  { clave: 'receptor_voz', texto: 'Receptor de voz' },
  { clave: 'pantalla', texto: 'Pantalla' },
  { clave: 'botones', texto: 'Botones' },
  { clave: 'golpes', texto: 'Golpes' },
  { clave: 'mojado', texto: 'Mojado' },
];

/** Estatus de una reparación normal. */
export const ESTADOS_REPARACION: { valor: EstadoOrden; etiqueta: string }[] = [
  { valor: 'recibido', etiqueta: 'Recibido' },
  { valor: 'diagnostico', etiqueta: 'En diagnóstico' },
  { valor: 'en_reparacion', etiqueta: 'En reparación' },
  { valor: 'esperando_repuesto', etiqueta: 'Esperando repuesto' },
  { valor: 'reparado', etiqueta: 'Listo' },
  { valor: 'no_quedo', etiqueta: 'No quedó' },
  { valor: 'entregado', etiqueta: 'Entregado' },
  { valor: 'cancelado', etiqueta: 'Cancelado' },
];

/** Una liberación (orden con bloqueo de operador, FRP, iCloud, MDM o Knox) solo tiene estos 4. */
export const ESTADOS_LIBERACION: { valor: EstadoOrden; etiqueta: string }[] = [
  { valor: 'recibido', etiqueta: 'Recibido' },
  { valor: 'en_proceso', etiqueta: 'En proceso' },
  { valor: 'reparado', etiqueta: 'Listo' },
  { valor: 'rechazado', etiqueta: 'Rechazado' },
];

/** Todos (para filtros y para mostrar el nombre de cualquier estatus). */
export const ESTADOS_ORDEN: { valor: EstadoOrden; etiqueta: string }[] = [
  ...ESTADOS_REPARACION,
  { valor: 'en_proceso', etiqueta: 'En proceso (liberación)' },
  { valor: 'rechazado', etiqueta: 'Rechazado (liberación)' },
];

/** Estatus que se le pueden poner a una orden según sus bloqueos. */
export function estadosPara(bloqueos: string[] | null | undefined) {
  return bloqueos?.length ? ESTADOS_LIBERACION : ESTADOS_REPARACION;
}

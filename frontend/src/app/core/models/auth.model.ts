export interface TallerActual {
  id: number;
  nombre: string;
  logo_url: string | null;
  vigente: boolean;
  estado_texto: string;
  /** La API de WhatsApp Cloud está activa: los botones de WhatsApp envían directo. */
  whatsapp_api?: boolean;
}

export interface Usuario {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions?: string[];
  es_superadmin?: boolean;
  sucursal_id?: number | null;
  sucursales?: { id: number; nombre: string }[];
  taller?: TallerActual | null;
}

export interface LoginResponse {
  token: string;
  user: Usuario;
  message?: string;
}

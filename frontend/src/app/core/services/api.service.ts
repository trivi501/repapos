import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';

type Params = Record<string, string | number | boolean | null | undefined>;

/** Acceso genérico a la API. Los parámetros vacíos no se envían. */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  private params(params?: Params): HttpParams {
    let p = new HttpParams();
    for (const [k, v] of Object.entries(params ?? {})) {
      if (v !== null && v !== undefined && v !== '' && v !== false) p = p.set(k, v === true ? 1 : v);
    }
    return p;
  }

  get<T>(ruta: string, params?: Params) {
    return this.http.get<T>(`${environment.apiUrl}/${ruta}`, { params: this.params(params) });
  }

  post<T>(ruta: string, cuerpo: unknown = {}) {
    return this.http.post<T>(`${environment.apiUrl}/${ruta}`, cuerpo);
  }

  put<T>(ruta: string, cuerpo: unknown = {}) {
    return this.http.put<T>(`${environment.apiUrl}/${ruta}`, cuerpo);
  }

  patch<T>(ruta: string, cuerpo: unknown = {}) {
    return this.http.patch<T>(`${environment.apiUrl}/${ruta}`, cuerpo);
  }

  delete<T = void>(ruta: string, cuerpo?: unknown) {
    return this.http.delete<T>(`${environment.apiUrl}/${ruta}`, { body: cuerpo });
  }

  /** Abre un PDF protegido en otra pestaña (el navegador no puede mandar el token en un enlace normal). */
  async abrirPdf(ruta: string): Promise<void> {
    const ventana = window.open('', '_blank');
    const blob = await firstValueFrom(this.http.get(`${environment.apiUrl}/${ruta}`, { responseType: 'blob' }));
    const url = URL.createObjectURL(blob);
    if (ventana) ventana.location.href = url;
    else window.open(url, '_blank');
  }

  /** Descarga un archivo protegido (CSV, respaldo). */
  async descargar(ruta: string, nombre: string, params?: Params): Promise<void> {
    const blob = await firstValueFrom(
      this.http.get(`${environment.apiUrl}/${ruta}`, { responseType: 'blob', params: this.params(params) }),
    );
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nombre;
    a.click();
    URL.revokeObjectURL(a.href);
  }
}

/** Texto para el usuario a partir de un error de la API. */
export function textoError(error: HttpErrorResponse, porDefecto = 'No se pudo completar la operación.'): string {
  const errores = error.error?.errors;
  if (errores) return (Object.values(errores) as string[][]).flat().join(' ');
  return error.error?.message || porDefecto;
}

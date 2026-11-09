import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';
import { Paginado, UsuarioAdmin } from '../models/taller.model';

@Injectable({ providedIn: 'root' })
export class UsuarioService {
  private readonly baseUrl = `${environment.apiUrl}/usuarios`;

  constructor(private readonly http: HttpClient) {}

  listar(pagina = 1, buscar = '') {
    const params = new HttpParams().set('page', pagina).set('buscar', buscar);
    return this.http.get<Paginado<UsuarioAdmin>>(this.baseUrl, { params });
  }

  crear(datos: { name: string; email: string; password: string; sucursal_id: number | null; roles: string[] }) {
    return this.http.post<UsuarioAdmin>(this.baseUrl, datos);
  }

  actualizar(id: number, datos: { name: string; email: string; password?: string; sucursal_id: number | null; roles: string[] }) {
    return this.http.put<UsuarioAdmin>(`${this.baseUrl}/${id}`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }
}

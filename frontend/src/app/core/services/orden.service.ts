import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';
import { OrdenReparacion, Paginado, Sucursal } from '../models/taller.model';

@Injectable({ providedIn: 'root' })
export class OrdenService {
  private readonly baseUrl = `${environment.apiUrl}/ordenes`;

  constructor(private readonly http: HttpClient) {}

  listar(pagina = 1, estado = '', buscar = '') {
    let params = new HttpParams().set('page', pagina);
    if (estado) params = params.set('estado', estado);
    if (buscar) params = params.set('q', buscar);
    return this.http.get<Paginado<OrdenReparacion>>(this.baseUrl, { params });
  }

  obtener(id: number) {
    return this.http.get<OrdenReparacion>(`${this.baseUrl}/${id}`);
  }

  crear(datos: Record<string, unknown>) {
    return this.http.post<OrdenReparacion>(this.baseUrl, datos);
  }

  actualizar(id: number, datos: Record<string, unknown>) {
    return this.http.put<OrdenReparacion>(`${this.baseUrl}/${id}`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  listarSucursales() {
    return this.http.get<Sucursal[]>(`${environment.apiUrl}/sucursales`);
  }

  listarTecnicos() {
    return this.http.get<{ id: number; name: string }[]>(`${environment.apiUrl}/ordenes-tecnicos`);
  }
}

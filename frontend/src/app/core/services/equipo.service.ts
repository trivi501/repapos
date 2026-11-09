import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';
import { Equipo, Paginado } from '../models/taller.model';

@Injectable({ providedIn: 'root' })
export class EquipoService {
  private readonly baseUrl = `${environment.apiUrl}/equipos`;

  constructor(private readonly http: HttpClient) {}

  listar(pagina = 1, buscar = '', clienteId?: number) {
    let params = new HttpParams().set('page', pagina).set('buscar', buscar);
    if (clienteId) params = params.set('cliente_id', clienteId);
    return this.http.get<Paginado<Equipo>>(this.baseUrl, { params });
  }

  obtener(id: number) {
    return this.http.get<Equipo>(`${this.baseUrl}/${id}`);
  }

  crear(datos: Partial<Equipo>) {
    return this.http.post<Equipo>(this.baseUrl, datos);
  }

  actualizar(id: number, datos: Partial<Equipo>) {
    return this.http.put<Equipo>(`${this.baseUrl}/${id}`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  listarPorCliente(clienteId: number) {
    const params = new HttpParams().set('cliente_id', clienteId).set('por_pagina', 100);
    return this.http.get<Paginado<Equipo>>(this.baseUrl, { params });
  }
}

import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';
import { Cliente, Paginado } from '../models/taller.model';

@Injectable({ providedIn: 'root' })
export class ClienteService {
  private readonly baseUrl = `${environment.apiUrl}/clientes`;

  constructor(private readonly http: HttpClient) {}

  listar(pagina = 1, buscar = '') {
    const params = new HttpParams().set('page', pagina).set('buscar', buscar);
    return this.http.get<Paginado<Cliente>>(this.baseUrl, { params });
  }

  obtener(id: number) {
    return this.http.get<Cliente>(`${this.baseUrl}/${id}`);
  }

  crear(datos: Partial<Cliente>) {
    return this.http.post<Cliente>(this.baseUrl, datos);
  }

  actualizar(id: number, datos: Partial<Cliente>) {
    return this.http.put<Cliente>(`${this.baseUrl}/${id}`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  listarTodos() {
    const params = new HttpParams().set('por_pagina', 200);
    return this.http.get<Paginado<Cliente>>(this.baseUrl, { params });
  }
}

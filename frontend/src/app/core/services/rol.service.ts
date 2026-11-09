import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';
import { Permiso, Rol } from '../models/taller.model';

@Injectable({ providedIn: 'root' })
export class RolService {
  private readonly baseUrl = `${environment.apiUrl}/roles`;

  constructor(private readonly http: HttpClient) {}

  listar() {
    return this.http.get<Rol[]>(this.baseUrl);
  }

  crear(datos: { name: string; permissions: string[] }) {
    return this.http.post<Rol>(this.baseUrl, datos);
  }

  actualizar(id: number, datos: { name: string; permissions: string[] }) {
    return this.http.put<Rol>(`${this.baseUrl}/${id}`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  listarPermisos() {
    return this.http.get<Permiso[]>(`${environment.apiUrl}/permisos`);
  }
}

import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { from, switchMap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { CategoriaFoto, FotoOrden } from '../models/taller.model';
import { comprimirImagen } from '../utils/comprimir-imagen';

@Injectable({ providedIn: 'root' })
export class FotoService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiUrl}/fotos`;

  subir(archivo: File, categoria: CategoriaFoto, lote: string) {
    return from(comprimirImagen(archivo)).pipe(
      switchMap((comprimido) => {
        const datos = new FormData();
        datos.append('archivo', comprimido);
        datos.append('categoria', categoria);
        datos.append('lote', lote);
        return this.http.post<FotoOrden>(this.baseUrl, datos);
      }),
    );
  }

  listarLote(lote: string) {
    return this.http.get<FotoOrden[]>(this.baseUrl, { params: new HttpParams().set('lote', lote) });
  }

  eliminar(id: number) {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  enlaceMovil(lote: string, categoria: CategoriaFoto) {
    const params = new HttpParams().set('lote', lote).set('categoria', categoria);
    return this.http.get<{ url: string; expira: string }>(`${environment.apiUrl}/fotos-enlace-movil`, { params });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/services/auth.service';
import { AvisosService } from '../../core/services/avisos.service';
import { WhatsAppApi } from './whatsapp-api';

interface Datos {
  lada: string;
  mensaje_listo: string;
  mensaje_no_quedo: string;
  locatarios: { id: number; nombre: string; whatsapp: string | null }[];
  textos: { clave: string; titulo: string; valor: string; renglones: number }[];
  taller: { nombre: string; logo_url: string | null };
}

@Component({
  selector: 'app-configuracion',
  imports: [FormsModule, WhatsAppApi],
  templateUrl: './configuracion.html',
})
export class Configuracion implements OnInit {
  private readonly api = inject(ApiService);
  private readonly avisos = inject(AvisosService);
  readonly auth = inject(AuthService);

  readonly cargado = signal(false);
  readonly locatarios = signal<Datos['locatarios']>([]);
  readonly textos = signal<Datos['textos']>([]);
  readonly logoUrl = signal<string | null>(null);

  readonly puedeEditar = this.auth.tienePermiso('configuracion.editar');
  nombreTaller = '';
  lada = '52';
  mensajeListo = '';
  mensajeNoQuedo = '';
  quitarLogo = false;
  private logo: File | null = null;

  ngOnInit(): void {
    this.cargar();
  }

  private cargar(): void {
    this.api.get<Datos>('configuracion').subscribe({
      next: (d) => {
        this.nombreTaller = d.taller.nombre;
        this.lada = d.lada;
        this.mensajeListo = d.mensaje_listo;
        this.mensajeNoQuedo = d.mensaje_no_quedo;
        this.quitarLogo = false;
        this.logo = null;
        this.logoUrl.set(d.taller.logo_url);
        this.locatarios.set(d.locatarios);
        this.textos.set(d.textos);
        this.cargado.set(true);
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }

  llaves(nombre: string): string {
    return `{${nombre}}`;
  }

  alElegirLogo(e: Event): void {
    this.logo = (e.target as HTMLInputElement).files?.[0] ?? null;
  }

  guardar(): void {
    // multipart: puede llevar el archivo del logo
    const f = new FormData();
    f.append('nombre_taller', this.nombreTaller);
    f.append('lada', this.lada);
    f.append('mensaje_listo', this.mensajeListo);
    f.append('mensaje_no_quedo', this.mensajeNoQuedo);
    if (this.quitarLogo) f.append('quitar_logo', '1');
    if (this.logo) f.append('logo', this.logo);
    for (const l of this.locatarios()) f.append(`whatsapp[${l.id}]`, l.whatsapp ?? '');
    for (const t of this.textos()) f.append(`textos[${t.clave}]`, t.valor);

    this.api.post<{ message: string }>('configuracion', f).subscribe({
      next: (r) => {
        this.avisos.ok(r.message);
        this.auth.refrescar().subscribe();   // nombre y logo salen en el menú
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.avisos.fallo(e),
    });
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, OnDestroy, inject, input, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import QRCode from 'qrcode';
import { Subscription, interval, startWith, switchMap } from 'rxjs';
import { CategoriaFoto, FotoOrden } from '../../core/models/taller.model';
import { FotoService } from '../../core/services/foto.service';

const INTERVALO_SINCRONIZACION_MS = 3000;

/**
 * Zona de fotos de una categoría (antes / proceso / después): arrastrar y soltar,
 * selector de archivos y subida desde el celular por QR. Las fotos se suben como
 * borrador del `lote`; el formulario padre las asocia a la orden al guardar.
 */
@Component({
  selector: 'app-zona-fotos',
  standalone: true,
  templateUrl: './zona-fotos.html',
  styleUrl: './zona-fotos.scss',
})
export class ZonaFotos implements OnDestroy {
  private readonly fotoService = inject(FotoService);
  private readonly destroyRef = inject(DestroyRef);

  readonly titulo = input.required<string>();
  readonly descripcion = input('');
  readonly categoria = input.required<CategoriaFoto>();
  readonly lote = input.required<string>();
  readonly fotos = input<FotoOrden[]>([]);
  readonly editable = input(true);

  readonly agregadas = output<FotoOrden[]>();
  readonly eliminada = output<number>();

  readonly arrastrando = signal(false);
  readonly subiendo = signal(0);
  readonly error = signal<string | null>(null);

  readonly qrAbierto = signal(false);
  readonly qrDataUrl = signal<string | null>(null);
  readonly enlace = signal('');
  readonly recibidasQr = signal(0);
  private sincronizacion?: Subscription;

  alArrastrar(evento: DragEvent): void {
    evento.preventDefault();
    this.arrastrando.set(true);
  }

  alSoltar(evento: DragEvent): void {
    evento.preventDefault();
    this.arrastrando.set(false);
    this.subir([...(evento.dataTransfer?.files ?? [])]);
  }

  alElegir(evento: Event): void {
    const entrada = evento.target as HTMLInputElement;
    this.subir([...(entrada.files ?? [])]);
    entrada.value = '';
  }

  private subir(archivos: File[]): void {
    const imagenes = archivos.filter((a) => a.type.startsWith('image/') || /\.(heic|heif)$/i.test(a.name));
    this.error.set(imagenes.length < archivos.length ? 'Solo se aceptan imágenes.' : null);

    for (const archivo of imagenes) {
      this.subiendo.update((n) => n + 1);
      this.fotoService
        .subir(archivo, this.categoria(), this.lote())
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (foto) => this.agregadas.emit([foto]),
          error: (e: HttpErrorResponse) => {
            this.error.set(e.error?.errors?.archivo?.[0] ?? `No se pudo subir ${archivo.name}.`);
            this.subiendo.update((n) => n - 1);
          },
          complete: () => this.subiendo.update((n) => n - 1),
        });
    }
  }

  eliminar(foto: FotoOrden): void {
    if (foto.orden_reparacion_id && !confirm('Esta foto ya está guardada en la orden. ¿Eliminarla definitivamente?')) return;
    this.fotoService.eliminar(foto.id).subscribe({
      next: () => this.eliminada.emit(foto.id),
      error: () => this.error.set('No se pudo eliminar la foto.'),
    });
  }

  abrirQr(): void {
    this.qrAbierto.set(true);
    this.qrDataUrl.set(null);
    this.recibidasQr.set(0);

    this.fotoService.enlaceMovil(this.lote(), this.categoria()).subscribe({
      next: async ({ url }) => {
        this.enlace.set(url);
        this.qrDataUrl.set(await QRCode.toDataURL(url, { width: 240, margin: 1 }));
        this.iniciarSincronizacion();
      },
      error: () => {
        this.qrAbierto.set(false);
        this.error.set('No se pudo generar el código QR.');
      },
    });
  }

  cerrarQr(): void {
    this.qrAbierto.set(false);
    this.sincronizacion?.unsubscribe();
  }

  /** Mientras el QR está abierto, consulta el lote para mostrar las fotos que llegan del celular. */
  private iniciarSincronizacion(): void {
    this.sincronizacion?.unsubscribe();
    const conocidas = new Set(this.fotos().map((f) => f.id));
    let recibidas = 0;

    this.sincronizacion = interval(INTERVALO_SINCRONIZACION_MS)
      .pipe(
        startWith(0),
        switchMap(() => this.fotoService.listarLote(this.lote())),
      )
      .subscribe((fotosLote) => {
        const nuevas = fotosLote.filter((f) => f.categoria === this.categoria() && !conocidas.has(f.id));
        if (!nuevas.length) return;
        nuevas.forEach((f) => conocidas.add(f.id));
        recibidas += nuevas.length;
        this.recibidasQr.set(recibidas);
        this.agregadas.emit(nuevas);
      });
  }

  ngOnDestroy(): void {
    this.sincronizacion?.unsubscribe();
  }
}

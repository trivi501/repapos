import { Component, output, signal } from '@angular/core';

/** Programa "RepaPOS Conector" (carpeta conector/ del repositorio): lee el teléfono conectado por USB en esta PC. */
export const CONECTOR_USB = 'http://127.0.0.1:8765';

/** Lo que contesta el conector por cada teléfono conectado. */
export interface TelefonoUsb {
  tipo: 'android' | 'iphone' | 'ipad';
  marca: string | null;
  modelo: string | null;
  modelo_tecnico?: string;
  serial: string | null;
  imei: string | null;
  udid?: string;
  modo: string;
  sistema?: string;
  aviso?: string;
  error?: string;
}

/** "Samsung Galaxy A56 5G (SM-A566B)": marca, modelo comercial y, si es distinto, el de fábrica. */
export function nombreTelefonoUsb(t: TelefonoUsb, conMarca = true): string {
  const modelo = t.modelo && t.modelo_tecnico && t.modelo_tecnico !== t.modelo ? `${t.modelo} (${t.modelo_tecnico})` : (t.modelo ?? '');
  return (conMarca ? [t.marca, modelo] : [modelo]).filter(Boolean).join(' ');
}

/**
 * Botón "Detectar teléfono conectado por USB": le pregunta al conector, deja elegir si hay varios
 * y avisa con `elegido` para que el formulario llene sus campos.
 */
@Component({
  selector: 'app-detectar-usb',
  template: `
    <div class="detectar-usb">
      <button type="button" class="btn btn-secondary btn-sm" (click)="detectar()" [disabled]="detectando()">
        🔌 {{ detectando() ? 'Buscando…' : 'Detectar teléfono conectado por USB' }}
      </button>
      @if (aviso()) {
        <span class="ayuda">{{ aviso() }}</span>
      }
      @if (opciones().length > 1) {
        <div class="lista-usb">
          @for (t of opciones(); track $index) {
            <button type="button" class="btn btn-secondary btn-sm" (click)="elegir(t)">
              {{ t.marca || 'Teléfono' }} {{ t.modelo }} · {{ t.serial || t.imei || t.udid || 'sin serie' }} ({{ t.modo }})
            </button>
          }
        </div>
      }
    </div>
  `,
})
export class DetectarUsb {
  readonly elegido = output<TelefonoUsb>();

  readonly opciones = signal<TelefonoUsb[]>([]);
  readonly detectando = signal(false);
  readonly aviso = signal('');

  async detectar(): Promise<void> {
    this.detectando.set(true);
    this.aviso.set('');
    this.opciones.set([]);
    try {
      // fetch directo (sin el token del sistema): el conector solo escucha en esta PC
      const r = await fetch(`${CONECTOR_USB}/telefonos`, { cache: 'no-store', signal: AbortSignal.timeout(20000) });
      const lista = ((await r.json()) as TelefonoUsb[]).filter((t) => !t.error);
      if (!lista.length) this.aviso.set('No se detectó ningún teléfono. Conéctalo, desbloquéalo y elige "Transferir archivos".');
      else if (lista.length === 1) this.elegir(lista[0]);
      else {
        this.opciones.set(lista);
        this.aviso.set('Hay varios teléfonos conectados: elige cuál es.');
      }
    } catch {
      this.aviso.set('No responde el conector. Abre "RepaPOS-Conector.exe" en esta PC (queda junto al reloj) y vuelve a intentar.');
    } finally {
      this.detectando.set(false);
    }
  }

  elegir(t: TelefonoUsb): void {
    this.opciones.set([]);
    const quien = nombreTelefonoUsb(t) || 'Teléfono';
    const datos = [t.imei ? `IMEI ${t.imei}` : '', t.serial ? `Serie ${t.serial}` : ''].filter(Boolean).join(' · ') || 'sin serie';
    this.aviso.set(`✔ ${quien} · ${t.modo} · ${datos}${t.aviso ? ' — ' + t.aviso : ''}`);
    this.elegido.emit(t);
  }
}

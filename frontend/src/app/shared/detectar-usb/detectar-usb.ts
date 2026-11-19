import { Component, input, output, signal } from '@angular/core';

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
  /** Samsung: firmware (A566EXXS6AYGE) y, en modo Descarga, el almacenamiento ("256 GB"). */
  firmware?: string;
  almacenamiento?: string;
  aviso?: string;
  error?: string;
}

/** "Samsung Galaxy A56 5G (SM-A566B)": marca, modelo comercial y, si es distinto, el de fábrica. */
export function nombreTelefonoUsb(t: TelefonoUsb, conMarca = true): string {
  const modelo = t.modelo && t.modelo_tecnico && t.modelo_tecnico !== t.modelo ? `${t.modelo} (${t.modelo_tecnico})` : (t.modelo ?? '');
  // "OPPO Reno14 F 5G" ya trae la marca: no se repite
  const yaTraeMarca = !!t.marca && modelo.toLowerCase().startsWith(t.marca.toLowerCase());
  return (conMarca && !yaTraeMarca ? [t.marca, modelo] : [modelo]).filter(Boolean).join(' ');
}

// ---------------------------------------------------------------- puente por ventana (página en el servidor)

/**
 * Abre un momento la ventanita "puente" del conector (http://127.0.0.1:8765/puente). Esa página es del conector,
 * así que el navegador no le pide el permiso de red local: lee los teléfonos y los manda aquí con postMessage.
 * El conector solo se los manda a repapos.tech o a esta PC. Devuelve null si no se abrió o no contestó.
 */
export function leerPorVentanaDelConector(): Promise<TelefonoUsb[] | null> {
  return new Promise((resolver) => {
    let ventana: Window | null = null;
    let vigilar: ReturnType<typeof setInterval> | undefined;
    const terminar = (lista: TelefonoUsb[] | null) => {
      removeEventListener('message', alMensaje);
      clearTimeout(limite);
      clearInterval(vigilar);
      try { ventana?.close(); } catch { /* ya se cerró */ }
      resolver(lista);
    };
    const alMensaje = (e: MessageEvent) => {
      if (e.origin !== CONECTOR_USB || e.data?.tipo !== 'repapos-telefonos' || !Array.isArray(e.data.lista)) return;
      terminar((e.data.lista as TelefonoUsb[]).filter((t) => !t.error));
    };
    addEventListener('message', alMensaje);
    const limite = setTimeout(() => terminar(null), 25000);
    const ancho = 420, alto = 180;
    ventana = window.open(`${CONECTOR_USB}/puente?origen=${encodeURIComponent(location.origin)}`, 'repapos-conector',
      `width=${ancho},height=${alto},left=${Math.round(screenX + (outerWidth - ancho) / 2)},top=${Math.round(screenY + 120)}`);
    if (!ventana) { terminar(null); return; }   // el navegador bloqueó la ventana
    // si se cierra sin mandar nada (conector cerrado, error), no esperar los 25 s
    vigilar = setInterval(() => { if (ventana?.closed) setTimeout(() => terminar(null), 300); }, 400);
  });
}

// ---------------------------------------------------------------- Samsung por Web Serial (sin conector)

const SAMSUNG = 0x04e8;
const SAMSUNG_DESCARGA = 0x685d;

/** Lo mínimo de Web Serial que se usa (Chrome y Edge; no está en los tipos de TypeScript). */
interface PuertoSerie {
  getInfo(): { usbVendorId?: number; usbProductId?: number };
  open(o: { baudRate: number }): Promise<void>;
  close(): Promise<void>;
  setSignals?(s: { dataTerminalReady?: boolean; requestToSend?: boolean }): Promise<void>;
  readable: ReadableStream<Uint8Array> | null;
  writable: WritableStream<Uint8Array> | null;
}

/**
 * Lee los Samsung conectados directo desde el navegador, por el puerto COM de su módem:
 * - encendido (MTP): AT+DEVCONINFO → modelo de fábrica, serie, IMEI y firmware;
 * - modo Descarga (Odin): DVIF → modelo y firmware.
 * La primera vez el navegador pide elegir el puerto del Samsung; después lo recuerda.
 * Devuelve null si el navegador no tiene Web Serial o no se eligió ningún puerto.
 */
export async function leerSamsungPorSerial(pedirPermiso: boolean): Promise<TelefonoUsb[] | null> {
  const serial = (navigator as unknown as { serial?: { getPorts(): Promise<PuertoSerie[]>; requestPort(o: unknown): Promise<PuertoSerie> } }).serial;
  if (!serial) return null;
  let puertos = (await serial.getPorts()).filter((p) => p.getInfo().usbVendorId === SAMSUNG);
  if (!puertos.length && pedirPermiso) {
    try {
      puertos = [await serial.requestPort({ filters: [{ usbVendorId: SAMSUNG }] })];
    } catch {
      return null;   // canceló o no hay Samsung conectado
    }
  }
  if (!puertos.length) return null;
  const lista: TelefonoUsb[] = [];
  for (const p of puertos) {
    const t = await leerPuertoSamsung(p).catch(() => null);
    if (t) lista.push(t);
  }
  return lista;
}

async function leerPuertoSamsung(p: PuertoSerie): Promise<TelefonoUsb | null> {
  const descarga = p.getInfo().usbProductId === SAMSUNG_DESCARGA;
  await p.open({ baudRate: 115200 });
  try {
    await p.setSignals?.({ dataTerminalReady: true, requestToSend: true });
    const resp = await pedirSerie(p, descarga ? 'DVIF' : 'AT+DEVCONINFO\r\n',
      descarga ? (s) => /@#[\s\S]*@#/.test(s) : (s) => /DEVCONINFO[\s\S]*OK|ERROR/.test(s), descarga ? 3000 : 5000);
    const t: TelefonoUsb = { tipo: 'android', marca: 'Samsung', modelo: null, serial: null, imei: null, modo: descarga ? 'Samsung Download (Odin)' : 'Módem (navegador)' };
    if (descarga) {
      const datos = Object.fromEntries((/@#([\s\S]*)@#/.exec(resp)?.[1] ?? '').split(';').map((x) => x.split('=').map((y) => y.trim())).filter((x) => x.length === 2));
      t.modelo = datos['MODEL'] || null;
      t.modelo_tecnico = datos['MODEL'] || undefined;
      if (datos['VER']) t.firmware = datos['VER'];
      if (/^\d+$/.test(datos['CAPA'] ?? '')) t.almacenamiento = `${datos['CAPA']} GB`;
      t.aviso = t.modelo ? 'En modo Download se lee el modelo, pero no la serie ni el IMEI: para esos, reinícialo normal.' : 'No respondió en modo Download.';
    } else {
      const datos = Object.fromEntries([...resp.matchAll(/(\w+)\(([^)]*)\)/g)].map((m) => [m[1].toUpperCase(), m[2].trim()]));
      t.modelo = datos['MN'] || null;
      t.modelo_tecnico = datos['MN'] || undefined;
      t.serial = datos['SN'] || null;
      t.imei = /^\d{14,16}$/.test(datos['IMEI'] ?? '') ? datos['IMEI'] : null;
      if (datos['VER']) t.firmware = datos['VER'].split('/')[0];
      if (!t.modelo && !t.serial) t.aviso = 'El Samsung no contestó: desbloquéalo y vuelve a intentar.';
    }
    return t;
  } finally {
    await p.close().catch(() => undefined);
  }
}

/** Manda el comando y junta lo que conteste hasta que esté completo o se acabe el tiempo. */
async function pedirSerie(p: PuertoSerie, comando: string, completa: (s: string) => boolean, ms: number): Promise<string> {
  const escritor = p.writable!.getWriter();
  await escritor.write(new TextEncoder().encode(comando));
  escritor.releaseLock();
  const lector = p.readable!.getReader();
  const decodificador = new TextDecoder();
  let texto = '';
  const fin = Date.now() + ms;
  try {
    while (Date.now() < fin && !completa(texto)) {
      const espera = new Promise<null>((r) => setTimeout(() => r(null), Math.max(50, fin - Date.now())));
      const leido = await Promise.race([lector.read(), espera]);
      if (!leido || leido.done) break;
      texto += decodificador.decode(leido.value, { stream: true });
    }
  } finally {
    await lector.cancel().catch(() => undefined);
    lector.releaseLock();
  }
  return texto;
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
        🔌 {{ detectando() ? 'Buscando…' : texto() }}
      </button>
      @if (aviso()) {
        <span class="ayuda">{{ aviso() }}</span>
      }
      @if (sinConector()) {
        <div class="aviso-conector">
          ⚠ <strong>No respondió el conector</strong>{{ sinConector() === 'samsung' ? ': solo se pudo leer el Samsung por su puerto. Para los demás equipos (iPhone, Motorola, Xiaomi…) hace falta el conector.' : '.' }}
          <ul>
            <li>Revisa que <strong>RepaPOS-Conector.exe</strong> esté abierto (icono junto al reloj).</li>
            @if (desdeServidor) {
              <li>Como abriste RepaPOS desde el servidor, el navegador debe permitirle hablar con tu PC: da clic en el
                candado o los ajustes junto a la dirección → <strong>Configuración del sitio</strong> →
                <strong>Acceso a la red local</strong> (o "Dispositivos de la red local") → <strong>Permitir</strong>, y vuelve a intentar.</li>
            }
          </ul>
        </div>
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
  readonly texto = input('Detectar teléfono conectado por USB');

  readonly opciones = signal<TelefonoUsb[]>([]);
  readonly detectando = signal(false);
  readonly aviso = signal('');
  /** El conector no contestó: 'todo' (no se leyó nada) o 'samsung' (solo se leyó el Samsung por su puerto). */
  readonly sinConector = signal<'' | 'todo' | 'samsung'>('');
  /** Abierto desde el servidor (no desde esta PC): el navegador pide permiso de "red local" para llegar al conector. */
  readonly desdeServidor = !['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);

  async detectar(): Promise<void> {
    this.detectando.set(true);
    this.aviso.set('');
    this.opciones.set([]);
    this.sinConector.set('');
    try {
      let lista: TelefonoUsb[] | null = null;
      try {
        // fetch directo (sin el token del sistema): el conector solo escucha en esta PC.
        // targetAddressSpace le dice a Chrome/Edge que es un programa de esta PC, para que pida el permiso de red local.
        const r = await fetch(`${CONECTOR_USB}/telefonos`, {
          cache: 'no-store', signal: AbortSignal.timeout(20000), targetAddressSpace: 'loopback',
        } as RequestInit);
        lista = ((await r.json()) as TelefonoUsb[]).filter((t) => !t.error);
      } catch {
        // desde el servidor el navegador puede bloquear la conexión con esta PC (permiso de "red local"):
        // entonces se abre un momento una ventanita del propio conector, que sí lo lee y le pasa los datos a la página
        if (this.desdeServidor) lista = await leerPorVentanaDelConector();
        if (!lista) {
          // sin conector: un Samsung se puede leer directo desde el navegador por su puerto COM (Web Serial)
          lista = await leerSamsungPorSerial(true);
          this.sinConector.set(lista?.length ? 'samsung' : 'todo');
          if (!lista?.length) return;
        }
      }
      if (!lista.length) this.aviso.set('No se detectó ningún teléfono. Conéctalo, desbloquéalo y elige "Transferir archivos".');
      else if (lista.length === 1) this.elegir(lista[0]);
      else {
        this.opciones.set(lista);
        this.aviso.set('Hay varios teléfonos conectados: elige cuál es.');
      }
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

import { Injectable } from '@angular/core';

/** Quita acentos y pasa a minúsculas para buscar "liberacion" y encontrar "Liberación". */
function normalizar(texto: string): string {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Le pone buscador a TODAS las listas desplegables (<select>) de la app, sin tocar cada pantalla:
 * al abrir una lista sale un cuadro para escribir y se filtran las opciones.
 * Al elegir, se cambia el <select> real y se avisa con "change"/"input", así que
 * formControlName, ngModel y (change) siguen funcionando igual.
 * Para dejar una lista normal: atributo data-sin-busqueda. En celular se usa la lista del teléfono.
 */
@Injectable({ providedIn: 'root' })
export class SelectBuscableService {
  private select: HTMLSelectElement | null = null;
  private panel: HTMLDivElement | null = null;
  private buscador: HTMLInputElement | null = null;
  private lista: HTMLDivElement | null = null;
  private visibles: HTMLOptionElement[] = [];
  private marcado = 0;
  private iniciado = false;

  iniciar(): void {
    if (this.iniciado || typeof document === 'undefined') return;
    this.iniciado = true;
    // en pantallas táctiles la lista nativa del teléfono es más cómoda
    if (window.matchMedia?.('(pointer: coarse)').matches) return;

    document.addEventListener('mousedown', (e) => this.alPresionar(e), true);
    document.addEventListener('keydown', (e) => this.alTeclear(e), true);
    window.addEventListener('resize', () => this.cerrar(false));
    document.addEventListener('scroll', (e) => {
      if (this.panel && !this.panel.contains(e.target as Node)) this.cerrar(false);
    }, true);
  }

  private aplica(el: EventTarget | null): el is HTMLSelectElement {
    return el instanceof HTMLSelectElement && !el.multiple && !el.disabled && el.size <= 1 && !el.hasAttribute('data-sin-busqueda');
  }

  private alPresionar(e: MouseEvent): void {
    if (this.panel?.contains(e.target as Node)) return;
    if (this.aplica(e.target) && e.button === 0) {
      e.preventDefault();
      if (this.select === e.target) {
        this.cerrar(true);
        return;
      }
      e.target.focus();
      this.abrir(e.target, '');
      return;
    }
    if (this.panel) this.cerrar(false);
  }

  private alTeclear(e: KeyboardEvent): void {
    if (this.panel) return; // el buscador maneja sus teclas
    if (!this.aplica(e.target) || e.ctrlKey || e.metaKey) return;
    const abre = e.key === 'Enter' || e.key === ' ' || e.key === 'F4' || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp'));
    const letra = e.key.length === 1 && e.key !== ' ' && !e.altKey;
    if (!abre && !letra) return;
    e.preventDefault();
    this.abrir(e.target, letra ? e.key : '');
  }

  private abrir(select: HTMLSelectElement, texto: string): void {
    this.cerrar(false);
    this.select = select;

    const panel = document.createElement('div');
    panel.className = 'select-buscable';
    panel.setAttribute('role', 'dialog');

    const buscador = document.createElement('input');
    buscador.type = 'search';
    buscador.placeholder = 'Buscar…';
    buscador.autocomplete = 'off';
    buscador.setAttribute('aria-label', 'Buscar en la lista');
    buscador.value = texto;

    const lista = document.createElement('div');
    lista.className = 'select-buscable-lista';
    lista.setAttribute('role', 'listbox');

    panel.append(buscador, lista);
    document.body.appendChild(panel);
    this.panel = panel;
    this.buscador = buscador;
    this.lista = lista;

    buscador.addEventListener('input', () => this.filtrar(true));
    buscador.addEventListener('keydown', (e) => this.teclasBuscador(e));
    lista.addEventListener('mousedown', (e) => e.preventDefault());
    lista.addEventListener('click', (e) => {
      const item = (e.target as HTMLElement).closest<HTMLElement>('[data-i]');
      if (item) this.elegir(this.visibles[Number(item.dataset['i'])]);
    });

    this.filtrar(!texto);
    this.posicionar();
    buscador.focus();
  }

  private posicionar(): void {
    if (!this.panel || !this.select) return;
    const r = this.select.getBoundingClientRect();
    const ancho = Math.max(r.width, 240);
    const alto = this.panel.offsetHeight;
    let top = r.bottom + 2;
    if (top + alto > window.innerHeight - 8 && r.top - alto - 2 > 8) top = r.top - alto - 2;
    const left = Math.min(Math.max(8, r.left), window.innerWidth - ancho - 8);
    Object.assign(this.panel.style, { top: `${top}px`, left: `${left}px`, width: `${ancho}px` });
  }

  /** Muestra las opciones que tienen todas las palabras escritas (sin importar acentos). */
  private filtrar(marcarActual: boolean): void {
    if (!this.select || !this.lista || !this.buscador) return;
    const palabras = normalizar(this.buscador.value).split(/\s+/).filter(Boolean);
    const opciones = Array.from(this.select.options);
    this.visibles = opciones.filter((o) => !o.hidden && palabras.every((p) => normalizar(o.text).includes(p)));

    const actual = this.visibles.indexOf(this.select.options[this.select.selectedIndex]);
    this.marcado = marcarActual && actual >= 0 ? actual : this.visibles.findIndex((o) => !o.disabled);

    this.lista.replaceChildren(
      ...(this.visibles.length
        ? this.visibles.map((o, i) => {
            const item = document.createElement('div');
            item.className = 'select-buscable-item';
            item.dataset['i'] = String(i);
            item.setAttribute('role', 'option');
            item.textContent = o.text.trim() || '—';
            if (o.disabled) item.classList.add('deshabilitado');
            if (o.selected) item.classList.add('elegido');
            return item;
          })
        : [Object.assign(document.createElement('div'), { className: 'select-buscable-vacio', textContent: 'Sin resultados' })]),
    );
    this.pintarMarcado();
  }

  private pintarMarcado(): void {
    if (!this.lista) return;
    this.lista.querySelectorAll('.marcado').forEach((el) => el.classList.remove('marcado'));
    const item = this.lista.querySelector<HTMLElement>(`[data-i="${this.marcado}"]`);
    item?.classList.add('marcado');
    item?.scrollIntoView({ block: 'nearest' });
  }

  private mover(paso: number): void {
    const n = this.visibles.length;
    if (!n) return;
    let i = this.marcado;
    for (let k = 0; k < n; k++) {
      i = (i + paso + n) % n;
      if (!this.visibles[i].disabled) break;
    }
    this.marcado = i;
    this.pintarMarcado();
  }

  private teclasBuscador(e: KeyboardEvent): void {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.mover(1);
        break;
      case 'ArrowUp':
        e.preventDefault();
        this.mover(-1);
        break;
      case 'Enter':
        e.preventDefault();
        if (this.visibles[this.marcado]) this.elegir(this.visibles[this.marcado]);
        break;
      case 'Escape':
        e.preventDefault();
        e.stopPropagation(); // que no cierre también el modal donde está la lista
        this.cerrar(true);
        break;
      case 'Tab':
        this.cerrar(true);
        break;
    }
  }

  private elegir(opcion: HTMLOptionElement | undefined): void {
    const select = this.select;
    if (!select || !opcion || opcion.disabled) return;
    const cambio = select.selectedIndex !== opcion.index;
    select.selectedIndex = opcion.index;
    this.cerrar(true);
    if (cambio) {
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  private cerrar(regresarFoco: boolean): void {
    const select = this.select;
    this.panel?.remove();
    this.panel = this.buscador = this.lista = this.select = null;
    this.visibles = [];
    if (regresarFoco) select?.focus();
  }
}

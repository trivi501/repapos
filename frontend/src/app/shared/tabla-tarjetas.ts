import { Directive, ElementRef, OnDestroy, afterNextRender, inject } from '@angular/core';

/**
 * En celular, cada renglón de la tabla se ve como una tarjeta: "Etiqueta: valor" uno debajo del otro.
 * Copia el texto de cada encabezado (th) a la celda de su columna como data-label, y lo vuelve a hacer
 * cuando cambian los renglones. El acomodo lo hace el CSS de .tabla-tarjetas (styles.scss).
 *
 *   <table tablaTarjetas> ... </table>
 *
 * Un encabezado vacío (la columna de acciones) no pone etiqueta: esa celda se muestra sola, a lo ancho.
 */
@Directive({
  selector: 'table[tablaTarjetas]',
  host: { class: 'tabla-tarjetas' },
})
export class TablaTarjetas implements OnDestroy {
  private readonly tabla = inject<ElementRef<HTMLTableElement>>(ElementRef).nativeElement;
  private observador?: MutationObserver;

  constructor() {
    afterNextRender(() => {
      this.etiquetar();
      this.observador = new MutationObserver(() => this.etiquetar());
      this.observador.observe(this.tabla, { childList: true, subtree: true, characterData: true });
    });
  }

  ngOnDestroy(): void {
    this.observador?.disconnect();
  }

  private etiquetar(): void {
    const encabezados = [...this.tabla.querySelectorAll<HTMLTableCellElement>('thead tr:last-child th')];
    // la columna real de cada encabezado, contando los que ocupan varias (colspan)
    const porColumna: string[] = [];
    for (const th of encabezados) {
      const texto = (th.textContent ?? '').replace(/\s+/g, ' ').trim();
      for (let i = 0; i < (th.colSpan || 1); i++) porColumna.push(texto);
    }
    for (const fila of this.tabla.querySelectorAll<HTMLTableRowElement>('tbody tr, tfoot tr')) {
      let columna = 0;
      for (const celda of fila.cells) {
        // una celda que ocupa todo (mensaje de "sin datos") no lleva etiqueta
        const etiqueta = celda.colSpan > 1 ? '' : (porColumna[columna] ?? '');
        if (celda.getAttribute('data-label') !== etiqueta) celda.setAttribute('data-label', etiqueta);
        // sin texto ni botones: en la tarjeta no se muestra ese renglón
        const vacia = !(celda.textContent ?? '').trim() && !celda.querySelector('button, a, input, select, img, svg');
        if (vacia !== celda.hasAttribute('data-vacia')) celda.toggleAttribute('data-vacia', vacia);
        columna += celda.colSpan || 1;
      }
    }
  }
}

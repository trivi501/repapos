import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import QRCode from 'qrcode';
import { OrdenReparacion } from '../../core/models/taller.model';
import { ApiService } from '../../core/services/api.service';
import { DineroPipe, FechaPipe } from '../../shared/ui';

interface DatosRecibo {
  orden: OrdenReparacion & {
    tiene_password: boolean;
    total: number;
    saldo: number;
    pagado: string;
    created_at: string;
    sucursal?: { nombre: string; direccion: string; telefono: string };
  };
  taller: { nombre: string; whatsapp: string | null; logo_url: string | null };
  revision_campos: Record<string, string>;
  terminos: string;
  aviso: string;
  enlace: string;
}

/** Comprobante de recepción para imprimir (sin PIN ni patrón). */
@Component({
  selector: 'app-recibo',
  imports: [RouterLink, DineroPipe, FechaPipe],
  templateUrl: './recibo.html',
  styleUrl: './recibo.scss',
})
export class Recibo implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  readonly d = signal<DatosRecibo | null>(null);
  readonly qr = signal<string | null>(null);

  ngOnInit(): void {
    this.api.get<DatosRecibo>(`ordenes/${this.route.snapshot.paramMap.get('id')}/recibo`).subscribe(async (d) => {
      this.d.set(d);
      this.qr.set(await QRCode.toDataURL(d.enlace, { width: 240, margin: 1 }));
    });
  }

  folio(n: number | undefined): string {
    return String(n ?? 0).padStart(4, '0');
  }

  /** Los 10 puntos de la revisión en dos filas de 5, como la nota de papel. */
  filasRevision(r: DatosRecibo) {
    const celdas = Object.entries(r.revision_campos).map(([clave, texto]) => ({ clave, texto, valor: r.orden.revision?.[clave] }));
    return [celdas.slice(0, 5), celdas.slice(5, 10)];
  }

  terminos(r: DatosRecibo): string[] {
    return r.terminos.split('\n').map((t) => t.trim()).filter(Boolean);
  }

  imprimir(): void {
    window.print();
  }
}

import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CategoriaFoto, Cliente, Equipo, FotoOrden, REVISION_ORDEN, Sucursal, estadosPara } from '../../core/models/taller.model';
import { AvisosService } from '../../core/services/avisos.service';
import { AuthService } from '../../core/services/auth.service';
import { ClienteService } from '../../core/services/cliente.service';
import { EquipoService } from '../../core/services/equipo.service';
import { OrdenService } from '../../core/services/orden.service';
import { PatronDesbloqueo } from '../../shared/patron-desbloqueo/patron-desbloqueo';
import { ZonaFotos } from '../../shared/zona-fotos/zona-fotos';

const ZONAS_FOTOS: { categoria: CategoriaFoto; titulo: string; descripcion: string }[] = [
  { categoria: 'antes', titulo: 'Fotos antes', descripcion: 'Estado inicial al recibir el equipo' },
  { categoria: 'proceso', titulo: 'Evidencias del proceso', descripcion: 'Desensamble, placa o microscopio' },
  { categoria: 'despues', titulo: 'Fotos después', descripcion: 'Resultado final listo para entrega' },
];

const SECCIONES_VISIBLES = [
  { clave: 'fotos_antes', etiqueta: 'Fotos de antes' },
  { clave: 'fotos_proceso', etiqueta: 'Evidencias del proceso' },
  { clave: 'fotos_despues', etiqueta: 'Fotos de después' },
  { clave: 'motivo', etiqueta: 'Motivo de ingreso' },
  { clave: 'fechas', etiqueta: 'Fechas y tiempos de entrega' },
  { clave: 'diagnostico', etiqueta: 'Diagnóstico, solución y notas para el cliente' },
  { clave: 'costos', etiqueta: 'Costo, abonos y saldo' },
];

/** UUID v4 para el lote de fotos; `crypto.randomUUID` solo existe en contextos seguros (https/localhost). */
function nuevoLote(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

const BLOQUEOS_CUENTA = [
  { clave: 'operador', etiqueta: 'Bloqueo de operador' },
  { clave: 'frp', etiqueta: 'Bloqueo FRP' },
  { clave: 'icloud', etiqueta: 'Bloqueo iCloud' },
  { clave: 'mdm', etiqueta: 'Bloqueo MDM' },
  { clave: 'knox', etiqueta: 'Bloqueo Knox' },
];

const METODOS_DESBLOQUEO = [
  { valor: 'patron', etiqueta: 'Patrón 3x3', icono: '⠿' },
  { valor: 'pin', etiqueta: 'PIN numérico', icono: '#' },
  { valor: 'clave', etiqueta: 'Contraseña', icono: '🔑' },
  { valor: 'ninguno', etiqueta: 'Sin bloqueo', icono: '🛡️' },
];

const FALLAS_COMUNES = [
  { icono: '📱', texto: 'Pantalla rota / Sin imagen' },
  { icono: '🔋', texto: 'Batería degradada / Se apaga' },
  { icono: '⚡', texto: 'Centro de carga / No enciende' },
  { icono: '💧', texto: 'Mojado / En corto' },
  { icono: '🔈', texto: 'Sin audio / Micrófono' },
  { icono: '📷', texto: 'Cámaras dañadas' },
  { icono: '🧩', texto: 'Tapa trasera estrellada' },
  { icono: '🔒', texto: 'Bloqueo / cuenta / software' },
];

const COMPONENTES_FISICOS = [
  { clave: 'pantalla', etiqueta: 'Pantalla' },
  { clave: 'bateria', etiqueta: 'Batería' },
  { clave: 'placa', etiqueta: 'Placa' },
  { clave: 'conectores', etiqueta: 'Conectores / puertos' },
  { clave: 'camaras', etiqueta: 'Cámaras' },
  { clave: 'botones', etiqueta: 'Botones' },
  { clave: 'carcasa', etiqueta: 'Carcasa / tapa' },
  { clave: 'audio', etiqueta: 'Audio / micrófono' },
];

const NIVELES_FISICOS = [
  { valor: 'bueno', etiqueta: 'Bueno' },
  { valor: 'regular', etiqueta: 'Regular' },
  { valor: 'danado', etiqueta: 'Dañado' },
  { valor: 'roto', etiqueta: 'Roto' },
  { valor: 'falta', etiqueta: 'Falta' },
];

function numeroTicket(): string {
  const hoy = new Date();
  const fecha = `${String(hoy.getFullYear()).slice(2)}${String(hoy.getMonth() + 1).padStart(2, '0')}${String(hoy.getDate()).padStart(2, '0')}`;
  const sufijo = Math.random().toString(36).slice(2, 6).toUpperCase();
  return `REP-${fecha}-${sufijo}`;
}

function aLocalInput(fecha: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${fecha.getFullYear()}-${pad(fecha.getMonth() + 1)}-${pad(fecha.getDate())}T${pad(fecha.getHours())}:${pad(fecha.getMinutes())}`;
}

function estimadoPorDefecto(): string {
  const manana = new Date();
  manana.setDate(manana.getDate() + 1);
  manana.setHours(18, 0, 0, 0);
  return aLocalInput(manana);
}

@Component({
  selector: 'app-reparacion-form',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink, PatronDesbloqueo, ZonaFotos],
  templateUrl: './reparacion-form.html',
  styleUrl: './reparacion-form.scss',
})
export class ReparacionForm implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly ordenService = inject(OrdenService);
  private readonly clienteService = inject(ClienteService);
  private readonly equipoService = inject(EquipoService);
  readonly authService = inject(AuthService);
  private readonly avisos = inject(AvisosService);

  readonly revisionCampos = REVISION_ORDEN;
  readonly zonasFotos = ZONAS_FOTOS;
  readonly seccionesVisibles = SECCIONES_VISIBLES;
  readonly bloqueosCuenta = BLOQUEOS_CUENTA;
  readonly metodosDesbloqueo = METODOS_DESBLOQUEO;
  readonly fallasComunes = FALLAS_COMUNES;
  readonly componentesFisicos = COMPONENTES_FISICOS;
  readonly nivelesFisicos = NIVELES_FISICOS;
  readonly tiposEquipo = ['celular', 'tablet', 'laptop', 'otro'];

  readonly clientes = signal<Cliente[]>([]);
  readonly equiposCliente = signal<Equipo[]>([]);
  readonly tecnicos = signal<{ id: number; name: string }[]>([]);
  readonly sucursales = signal<Sucursal[]>([]);

  readonly ordenId = signal<number | null>(null);
  readonly esEdicion = computed(() => this.ordenId() !== null);
  readonly resumenEdicion = signal<{ cliente: string; telefono: string; equipo: string; imei: string } | null>(null);

  readonly modoCliente = signal<'registrado' | 'nuevo'>('registrado');
  readonly modoEquipo = signal<'previo' | 'nuevo'>('previo');
  readonly bloqueoAbierto = signal(true);
  readonly fisicoAbierto = signal(true);
  readonly estadoFisico = signal<Record<string, string>>({});
  readonly revision = signal<Record<string, string>>({});
  readonly notasAbierto = signal(true);
  readonly fotosAbierto = signal(true);
  readonly visibilidadAbierto = signal(true);

  // Fotos subidas en este formulario quedan en borrador bajo `lote` hasta guardar la orden.
  readonly lote = signal(nuevoLote());
  readonly fotos = signal<FotoOrden[]>([]);
  readonly fotosPorCategoria = computed(() => {
    const grupos: Record<CategoriaFoto, FotoOrden[]> = { antes: [], proceso: [], despues: [] };
    for (const foto of this.fotos()) grupos[foto.categoria].push(foto);
    return grupos;
  });

  readonly guardando = signal(false);
  readonly errores = signal<string[]>([]);

  readonly formulario = this.fb.group({
    numero_orden: [numeroTicket(), Validators.required],
    cliente_id: [null as number | null],
    cliente_nuevo: this.fb.group({
      nombre: [''],
      apellido: [''],
      telefono: [''],
      telefono2: [''],
      email: [''],
    }),
    equipo_id: [null as number | null],
    equipo_nuevo: this.fb.group({
      tipo: ['celular'],
      marca: [''],
      modelo: [''],
      imei: [''],
      color: [''],
      accesorios: [''],
    }),
    bloqueo_tipo: ['patron'],
    bloqueo_valor: [''],
    bloqueo_pin: [''],
    bloqueos: this.fb.control<string[]>([]),
    tipo_servicio: ['reparacion', Validators.required],
    falla_reportada: ['', Validators.required],
    observaciones: [''],
    tecnico_id: [null as number | null],
    sucursal_id: [null as number | null],
    estimado_listo_at: [estimadoPorDefecto()],
    costo_estimado: [null as number | null],
    anticipo: [0 as number | null],
    metodo_pago: ['efectivo'],
    estado: ['recibido'],
    motivo_rechazo: [''],
    diagnostico: [''],
    solucion: [''],
    costo_final: [null as number | null],
    notas: [''],
    notas_cliente: [''],
    visible_cliente: [true],
    visibilidad: this.fb.control<string[]>(SECCIONES_VISIBLES.map((s) => s.clave)),
  });

  readonly valores = toSignal(this.formulario.valueChanges, { initialValue: this.formulario.getRawValue() });

  /** Con algún bloqueo (operador, FRP, iCloud, MDM, Knox) la orden es liberación: solo 4 estatus. */
  readonly esLiberacion = computed(() => (this.valores().bloqueos ?? []).length > 0);
  readonly estados = computed(() => estadosPara(this.valores().bloqueos));

  readonly saldo = computed(() => {
    const v = this.valores();
    // lo que se cobra es el costo final si ya se fijó (igual que en el backend)
    return Math.max(0, Number(v.costo_final ?? v.costo_estimado ?? 0) - Number(v.anticipo ?? 0));
  });

  readonly comprobante = computed(() => {
    const v = this.valores();
    const resumen = this.resumenEdicion();
    if (resumen) {
      return {
        folio: v.numero_orden ?? '',
        fecha: new Date(),
        cliente: resumen.cliente,
        telefono: resumen.telefono,
        equipo: resumen.equipo,
        imei: resumen.imei,
        falla: v.falla_reportada || 'Por diagnosticar en taller',
        costo: Number(v.costo_final ?? v.costo_estimado ?? 0),
        anticipo: Number(v.anticipo ?? 0),
      };
    }

    const cliente = this.modoCliente() === 'nuevo'
      ? [v.cliente_nuevo?.nombre, v.cliente_nuevo?.apellido].filter(Boolean).join(' ')
      : this.nombreCliente(v.cliente_id);
    const telefono = this.modoCliente() === 'nuevo'
      ? v.cliente_nuevo?.telefono
      : this.clientes().find((c) => c.id === v.cliente_id)?.telefono;

    let equipo = '';
    let imei = '';
    if (this.modoEquipo() === 'nuevo') {
      equipo = [v.equipo_nuevo?.marca, v.equipo_nuevo?.modelo].filter(Boolean).join(' ');
      imei = v.equipo_nuevo?.imei ?? '';
    } else {
      const e = this.equiposCliente().find((x) => x.id === v.equipo_id);
      equipo = e ? `${e.marca} ${e.modelo}` : '';
      imei = e?.imei ?? '';
    }

    return {
      folio: v.numero_orden ?? '',
      fecha: new Date(),
      cliente: cliente || 'Cliente Seleccionado',
      telefono: telefono || 'Registrado',
      equipo: equipo || 'Dispositivo a Recibir',
      imei: imei || 'Sin registrar',
      falla: v.falla_reportada || 'Por diagnosticar en taller',
      costo: Number(v.costo_estimado ?? 0),
      anticipo: Number(v.anticipo ?? 0),
    };
  });

  ngOnInit(): void {
    this.clienteService.listarTodos().subscribe((r) => this.clientes.set(r.data));
    this.ordenService.listarTecnicos().subscribe((t) => this.tecnicos.set(t));
    this.ordenService.listarSucursales().subscribe((s) => {
      this.sucursales.set(s);
      if (!this.formulario.controls.sucursal_id.value && s.length) {
        this.formulario.controls.sucursal_id.setValue(s[0].id);
      }
    });

    this.formulario.controls.cliente_id.valueChanges.subscribe((clienteId) => {
      this.formulario.controls.equipo_id.setValue(null);
      this.equiposCliente.set([]);
      if (clienteId) {
        this.equipoService.listarPorCliente(clienteId).subscribe((r) => this.equiposCliente.set(r.data));
      }
    });

    const id = this.route.snapshot.paramMap.get('id');
    if (id) {
      this.ordenId.set(Number(id));
      this.cargarOrden(Number(id));
    }
  }

  private cargarOrden(id: number): void {
    this.ordenService.obtener(id).subscribe((orden) => {
      this.resumenEdicion.set({
        cliente: `${orden.cliente?.nombre ?? ''} ${orden.cliente?.apellido ?? ''}`.trim(),
        telefono: orden.cliente?.telefono ?? '',
        equipo: `${orden.equipo?.marca ?? ''} ${orden.equipo?.modelo ?? ''}`.trim(),
        imei: orden.equipo?.imei ?? 'Sin registrar',
      });
      this.estadoFisico.set(orden.estado_fisico ?? {});
      this.revision.set(orden.revision ?? {});
      this.fotos.set(orden.fotos ?? []);
      this.formulario.patchValue(
        {
          numero_orden: orden.numero_orden,
          bloqueo_tipo: orden.bloqueo_tipo ?? 'ninguno',
          bloqueo_valor: orden.bloqueo_valor ?? '',
          bloqueo_pin: orden.bloqueo_pin ?? '',
          bloqueos: orden.bloqueos ?? [],
          tipo_servicio: orden.tipo_servicio ?? 'reparacion',
          falla_reportada: orden.falla_reportada,
          observaciones: orden.observaciones ?? '',
          tecnico_id: orden.tecnico_id ?? null,
          sucursal_id: orden.sucursal_id ?? null,
          estimado_listo_at: orden.estimado_listo_at ? aLocalInput(new Date(orden.estimado_listo_at)) : '',
          costo_estimado: orden.costo_estimado ? Number(orden.costo_estimado) : null,
          // al editar se muestra lo ya pagado; solo cambia con abonos desde la ficha
          anticipo: Number(orden.pagado ?? 0),
          metodo_pago: orden.metodo_pago ?? 'efectivo',
          estado: orden.estado,
          motivo_rechazo: orden.motivo_rechazo ?? '',
          diagnostico: orden.diagnostico ?? '',
          solucion: orden.solucion ?? '',
          costo_final: orden.costo_final ? Number(orden.costo_final) : null,
          notas: orden.notas ?? '',
          notas_cliente: orden.notas_cliente ?? '',
          visible_cliente: orden.visible_cliente ?? true,
          visibilidad: orden.visibilidad ?? SECCIONES_VISIBLES.map((s) => s.clave),
        },
        { emitEvent: false },
      );
      this.formulario.updateValueAndValidity();
    });
  }

  private nombreCliente(id: number | null | undefined): string {
    const c = this.clientes().find((x) => x.id === id);
    return c ? `${c.nombre} ${c.apellido ?? ''}`.trim() : '';
  }

  cambiarModoCliente(modo: 'registrado' | 'nuevo'): void {
    this.modoCliente.set(modo);
    if (modo === 'nuevo') {
      this.formulario.controls.cliente_id.setValue(null);
      this.modoEquipo.set('nuevo');
    } else {
      this.modoEquipo.set('previo');
    }
  }

  cambiarModoEquipo(modo: 'previo' | 'nuevo'): void {
    if (modo === 'previo' && this.modoCliente() === 'nuevo') return;
    this.modoEquipo.set(modo);
  }

  agregarFalla(texto: string): void {
    const control = this.formulario.controls.falla_reportada;
    const actual = (control.value ?? '').trim();
    if (actual.includes(texto)) return;
    control.setValue(actual ? `${actual}; ${texto}` : texto);
  }

  alternarBloqueo(clave: string, activo: boolean): void {
    const control = this.formulario.controls.bloqueos;
    const actuales = control.value ?? [];
    const antes = actuales.length > 0;
    control.setValue(activo ? [...actuales, clave] : actuales.filter((b) => b !== clave));
    const ahora = (control.value ?? []).length > 0;
    // al pasar de reparación a liberación (o al revés) el estatus tiene que ser uno de los permitidos
    if (antes !== ahora && !this.estados().some((e) => e.valor === this.formulario.controls.estado.value)) {
      const equivalente: Record<string, string> = ahora
        ? { no_quedo: 'rechazado', diagnostico: 'en_proceso', en_reparacion: 'en_proceso', esperando_repuesto: 'en_proceso' }
        : { en_proceso: 'en_reparacion', rechazado: 'no_quedo' };
      this.formulario.controls.estado.setValue(equivalente[this.formulario.controls.estado.value ?? ''] ?? 'recibido');
    }
  }

  cambiarMetodo(tipo: string): void {
    if (this.formulario.controls.bloqueo_tipo.value === tipo) return;
    // Un patrón no es un PIN válido (ni viceversa): al cambiar de método se descarta el valor anterior.
    this.formulario.patchValue({ bloqueo_tipo: tipo, bloqueo_valor: '' });
  }

  agregarFotos(nuevas: FotoOrden[]): void {
    this.fotos.update((actuales) => {
      const ids = new Set(actuales.map((f) => f.id));
      return [...actuales, ...nuevas.filter((f) => !ids.has(f.id))];
    });
  }

  quitarFoto(id: number): void {
    this.fotos.update((actuales) => actuales.filter((f) => f.id !== id));
  }

  alternarVisibilidad(clave: string, visible: boolean): void {
    const control = this.formulario.controls.visibilidad;
    const actuales = control.value ?? [];
    control.setValue(visible ? [...actuales, clave] : actuales.filter((c) => c !== clave));
  }

  marcarVisibilidad(todo: boolean): void {
    this.formulario.controls.visibilidad.setValue(todo ? SECCIONES_VISIBLES.map((s) => s.clave) : []);
  }

  /** Sí / No de la revisión; volver a tocar la misma opción la desmarca. */
  fijarRevision(clave: string, valor: string): void {
    this.revision.update((r) => {
      const { [clave]: actual, ...resto } = r;
      return actual === valor ? resto : { ...resto, [clave]: valor };
    });
  }

  fijarEstadoFisico(clave: string, valor: string): void {
    this.estadoFisico.update((e) => ({ ...e, [clave]: valor }));
  }

  private construirPayload(): Record<string, unknown> | null {
    const v = this.formulario.getRawValue();
    const errores: string[] = [];

    if (!this.esEdicion()) {
      if (this.modoCliente() === 'registrado' && !v.cliente_id) errores.push('Selecciona un cliente.');
      if (this.modoCliente() === 'nuevo' && (!v.cliente_nuevo.nombre || !v.cliente_nuevo.telefono)) {
        errores.push('El nombre y teléfono del nuevo cliente son obligatorios.');
      }
      if (this.modoEquipo() === 'previo' && !v.equipo_id) errores.push('Selecciona el equipo del cliente.');
      if (this.modoEquipo() === 'nuevo' && (!v.equipo_nuevo.marca || !v.equipo_nuevo.modelo)) {
        errores.push('La marca y el modelo del dispositivo son obligatorios.');
      }
    }
    if (!v.falla_reportada?.trim()) errores.push('Describe el problema reportado.');
    if (this.esEdicion() && v.estado === 'rechazado' && !v.motivo_rechazo?.trim()) {
      errores.push('Escribe por qué se rechazó la liberación.');
    }

    this.errores.set(errores);
    if (errores.length) return null;

    const base: Record<string, unknown> = {
      tecnico_id: v.tecnico_id,
      sucursal_id: v.sucursal_id,
      tipo_servicio: v.tipo_servicio,
      bloqueo_tipo: v.bloqueo_tipo,
      bloqueo_valor: v.bloqueo_tipo === 'ninguno' ? null : v.bloqueo_valor || null,
      bloqueo_pin: v.bloqueo_tipo === 'patron' ? v.bloqueo_pin || null : null,
      bloqueos: v.bloqueos ?? [],
      falla_reportada: v.falla_reportada,
      estado_fisico: this.estadoFisico(),
      revision: this.revision(),
      observaciones: v.observaciones || null,
      costo_estimado: v.costo_estimado,
      anticipo: v.anticipo ?? 0,
      metodo_pago: v.metodo_pago,
      // El input datetime-local no lleva zona horaria; se envía en ISO (UTC) para que el backend guarde la hora correcta.
      estimado_listo_at: v.estimado_listo_at ? new Date(v.estimado_listo_at).toISOString() : null,
      notas: v.notas || null,
      notas_cliente: v.notas_cliente || null,
      visible_cliente: v.visible_cliente ?? true,
      visibilidad: v.visibilidad ?? [],
      lote: this.lote(),
    };

    if (this.esEdicion()) {
      return {
        ...base,
        estado: v.estado,
        motivo_rechazo: v.estado === 'rechazado' ? v.motivo_rechazo?.trim() || null : null,
        diagnostico: v.diagnostico || null,
        solucion: v.solucion || null,
        costo_final: v.costo_final,
      };
    }

    const payload: Record<string, unknown> = { ...base, numero_orden: v.numero_orden };
    if (this.modoCliente() === 'registrado') payload['cliente_id'] = v.cliente_id;
    else payload['cliente_nuevo'] = v.cliente_nuevo;
    if (this.modoEquipo() === 'previo') payload['equipo_id'] = v.equipo_id;
    else payload['equipo_nuevo'] = v.equipo_nuevo;
    return payload;
  }

  guardar(crearOtro = false): void {
    const payload = this.construirPayload();
    if (!payload) return;

    this.guardando.set(true);
    const id = this.ordenId();
    const peticion = id ? this.ordenService.actualizar(id, payload) : this.ordenService.crear(payload);

    peticion.subscribe({
      next: (orden) => {
        this.guardando.set(false);
        if (crearOtro) {
          this.reiniciar();
          this.avisos.ok(`Orden folio ${orden.folio} guardada.`);
          return;
        }
        this.router.navigate(['/reparaciones', orden.id]).then(() => {
          if (id) this.avisos.ok('Cambios guardados.');
          else this.avisos.ok(`Orden folio ${orden.folio} guardada. Imprime el comprobante o mándale el ticket por WhatsApp.`);
        });
      },
      error: (error: HttpErrorResponse) => {
        this.guardando.set(false);
        const detalle = error.error?.errors
          ? (Object.values(error.error.errors) as string[][]).flat()
          : [error.error?.message ?? 'No se pudo guardar la reparación.'];
        this.errores.set(detalle);
      },
    });
  }

  private reiniciar(): void {
    this.formulario.reset({
      numero_orden: numeroTicket(),
      tipo_servicio: 'reparacion',
      bloqueo_tipo: 'patron',
      bloqueos: [],
      metodo_pago: 'efectivo',
      anticipo: 0,
      estado: 'recibido',
      estimado_listo_at: estimadoPorDefecto(),
      sucursal_id: this.sucursales()[0]?.id ?? null,
      cliente_nuevo: {},
      equipo_nuevo: { tipo: 'celular' },
      visible_cliente: true,
      visibilidad: SECCIONES_VISIBLES.map((s) => s.clave),
    });
    this.estadoFisico.set({});
    this.revision.set({});
    this.lote.set(nuevoLote());
    this.fotos.set([]);
    this.modoCliente.set('registrado');
    this.modoEquipo.set('previo');
    this.errores.set([]);
    this.clienteService.listarTodos().subscribe((r) => this.clientes.set(r.data));
  }

  cancelar(): void {
    const id = this.ordenId();
    this.router.navigate(id ? ['/reparaciones', id] : ['/reparaciones']);
  }
}

<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Locatario;
use App\Models\Taller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** WhatsApp: textos de los mensajes, lada y número de cada locatario. */
class ConfiguracionController extends Controller
{
    /** Textos de clientes finales que cada taller puede cambiar: clave => [título, texto de fábrica, renglones]. */
    public const TEXTOS_CLIENTES = [
        'ticket_whatsapp' => ['Ticket de la orden por WhatsApp', Ajuste::TICKET_WHATSAPP, 12],
        'ticket_venta_whatsapp' => ['Ticket de venta por WhatsApp ({productos} {descuento} {total} {metodo} {pie})', Ajuste::TICKET_VENTA_WHATSAPP, 7],
        'pie_ticket_venta' => ['Pie del ticket de venta (impreso y WhatsApp)', Ajuste::PIE_TICKET_VENTA, 3],
        'mensaje_orden_listo' => ['WhatsApp: equipo LISTO', Ajuste::MENSAJE_ORDEN_LISTO, 5],
        'mensaje_orden_no_quedo' => ['WhatsApp: NO QUEDÓ', Ajuste::MENSAJE_ORDEN_NO_QUEDO, 4],
        'mensaje_orden_estatus' => ['WhatsApp: otro estatus', Ajuste::MENSAJE_ORDEN_ESTATUS, 4],
        'aviso_recoger' => ['Advertencia junto al nombre del cliente', Ajuste::AVISO_RECOGER, 2],
        'terminos_orden' => ['Cláusulas del comprobante (una por renglón)', Ajuste::TERMINOS_ORDEN, 12],
    ];

    /** Muestra el logo de un taller (público: sale en el comprobante y en la página que consulta el cliente). */
    public function logo(Taller $taller)
    {
        abort_unless($taller->logo && Storage::exists($taller->logo), 404);

        return Storage::response($taller->logo, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    public function edit(Request $r)
    {
        $textos = [];
        foreach (self::TEXTOS_CLIENTES as $clave => [$titulo, $defecto, $renglones]) {
            $textos[] = ['clave' => $clave, 'titulo' => $titulo, 'valor' => Ajuste::valor($clave, $defecto), 'renglones' => $renglones];
        }
        $taller = $r->user()->taller;

        return response()->json([
            'lada' => Ajuste::valor('lada', '52'),
            'mensaje_listo' => Ajuste::valor('mensaje_listo', Ajuste::MENSAJE_LISTO),
            'mensaje_no_quedo' => Ajuste::valor('mensaje_no_quedo', Ajuste::MENSAJE_NO_QUEDO),
            'textos' => $textos,
            'taller' => ['nombre' => $taller->nombre, 'logo_url' => $taller->logoUrl()],
        ]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'nombre_taller' => ['nullable', 'string', 'max:120'],
            // sin SVG: puede traer código
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'lada' => ['required', 'regex:/^\d{1,4}$/'],
            'mensaje_listo' => ['required', 'string', 'max:2000'],
            'mensaje_no_quedo' => ['required', 'string', 'max:2000'],
            'textos' => ['array'],
            'textos.*' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach (self::TEXTOS_CLIENTES as $clave => [, $defecto]) {
            $texto = trim(str_replace("\r\n", "\n", (string) ($d['textos'][$clave] ?? '')));
            Ajuste::poner($clave, $texto === '' ? $defecto : $texto);   // vacío = regresar al texto de fábrica
        }

        // nombre y logo del taller: solo su administrador
        if ($r->user()->can('taller.editar')) {
            $taller = $r->user()->taller;
            if (trim($d['nombre_taller'] ?? '') !== '') {
                $taller->nombre = trim($d['nombre_taller']);
            }
            if ($r->boolean('quitar_logo') && $taller->logo) {
                Storage::delete($taller->logo);
                $taller->logo = null;
            }
            if ($r->hasFile('logo')) {
                if ($taller->logo) {
                    Storage::delete($taller->logo);
                }
                $taller->logo = $r->file('logo')->store('logos');   // storage/app/private/logos
            }
            $taller->save();
        }
        Ajuste::poner('lada', $d['lada']);
        Ajuste::poner('mensaje_listo', str_replace("\r\n", "\n", $d['mensaje_listo']));
        Ajuste::poner('mensaje_no_quedo', str_replace("\r\n", "\n", $d['mensaje_no_quedo']));

        return response()->json(['message' => 'Configuración guardada.']);
    }

    /** Pestaña "WhatsApp de locatarios": el número de cada locatario del taller. */
    public function whatsappLocatarios()
    {
        return response()->json(Locatario::orderBy('nombre')->get(['id', 'nombre', 'whatsapp']));
    }

    public function guardarWhatsappLocatarios(Request $r)
    {
        $d = $r->validate([
            'whatsapp' => ['required', 'array'],
            'whatsapp.*' => ['nullable', 'regex:/^[\d\s\-\+\(\)]{10,20}$/'],
        ], ['whatsapp.*.regex' => 'Revisa los números: cada WhatsApp debe tener al menos 10 dígitos.']);

        // solo locatarios de este taller (el modelo filtra por taller): un id ajeno no se toca
        foreach ($d['whatsapp'] as $id => $numero) {
            $numero = preg_replace('/\D/', '', (string) $numero);
            Locatario::whereKey($id)->update(['whatsapp' => $numero === '' ? null : $numero]);
        }

        return response()->json(['message' => 'WhatsApp de los locatarios guardados.']);
    }
}

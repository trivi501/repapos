<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden {{ $orden->folio }}</title>
    <style>
        /* dompdf: estilos sencillos, sin Tailwind */
        @page { margin: 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td { border: 1px solid #111; padding: 4px 5px; vertical-align: top; }
        .sin-borde td, td.sin-borde { border: 0; }
        .etq { font-size: 7px; font-weight: bold; text-transform: uppercase; color: #333; }
        .grande { font-size: 13px; }
        .folio { font-size: 22px; font-weight: bold; color: #b91c1c; }
        .rev td { text-align: center; width: 20%; }
        .clausulas { font-size: 6.5px; line-height: 1.25; }
        .clausulas li { margin-bottom: 1px; }
        .firma { border-top: 1px solid #111; text-align: center; font-size: 7px; padding-top: 2px; margin-top: 28px; }
        .mt { margin-top: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            @if ($logo)
                <td style="width: 70px; text-align: center; vertical-align: middle; border-right: 0;"><img src="{{ $logo }}" style="max-width: 64px; max-height: 64px;"></td>
            @endif
            <td style="{{ $logo ? 'border-left: 0;' : '' }}">
                <div style="font-size: 17px; font-weight: bold;">{{ $orden->taller->nombre }}</div>
                @if ($orden->sucursal->direccion)<div>{{ $orden->sucursal->direccion }}</div>@endif
                @if ($orden->sucursal->telefono || $orden->taller->whatsapp)<div>Tel. {{ $orden->sucursal->telefono ?: $orden->taller->whatsapp }}</div>@endif
                <div style="color: #555;">Sucursal {{ $orden->sucursal->nombre }}</div>
            </td>
            <td style="width: 150px; text-align: right;">
                <div class="etq">Orden de pedido</div>
                <div class="folio">N° {{ str_pad($orden->folio, 4, '0', STR_PAD_LEFT) }}</div>
                <div>Recepción: {{ $orden->created_at->format('d/m/Y H:i') }}</div>
                @if ($orden->fecha_entrega_estimada)<div>Promesa: {{ $orden->fecha_entrega_estimada->format('d/m/Y') }}</div>@endif
                @if ($orden->fecha_entrega_real)<div>Entrega: {{ $orden->fecha_entrega_real->format('d/m/Y H:i') }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="mt">
        <tr><td colspan="2"><span class="etq">Nombre</span> <span style="font-size: 7px;">({{ $aviso }})</span><div class="grande">{{ $orden->cliente->nombre_completo }}</div></td></tr>
        <tr>
            <td style="width: 50%;"><span class="etq">Teléfono</span><div class="grande">{{ $orden->cliente->telefono }}{{ $orden->cliente->telefono2 ? ' / ' . $orden->cliente->telefono2 : '' }}</div></td>
            <td><span class="etq">Equipo</span><div class="grande">{{ $orden->equipo?->nombre }} {{ $orden->equipo?->color }}</div>@if ($orden->equipo?->imei)<div style="font-size: 8px;">IMEI {{ $orden->equipo?->imei }}</div>@endif</td>
        </tr>
    </table>

    <table class="mt rev">
        @foreach (array_chunk(\App\Models\OrdenReparacion::REVISION, 5, true) as $fila)
            <tr>
                @foreach ($fila as $clave => $texto)
                    @php($v = $orden->revision[$clave] ?? null)
                    <td><div class="etq">{{ $texto }}</div>SÍ [{{ $v === 'si' ? 'X' : ' ' }}] &nbsp; NO [{{ $v === 'no' ? 'X' : ' ' }}]</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <table class="mt">
        <tr><td><span class="etq">Falla</span><div class="grande">{{ $orden->falla_reportada }}</div></td></tr>
        <tr><td><span class="etq">Observación</span><div>{{ $orden->observaciones ?: ' ' }}</div></td></tr>
        <tr><td style="font-size: 8px;">Password: {{ $orden->bloqueo_valor || $orden->bloqueo_pin ? 'registrado en el sistema (por seguridad no se imprime)' : 'no proporcionado' }} · Estatus: <b>{{ $orden->estado_texto }}</b></td></tr>
    </table>

    <table class="mt sin-borde">
        <tr>
            <td style="border: 1px solid #111; padding: 5px;">
                <div style="color: #b91c1c; font-weight: bold;">CLÁUSULAS <span style="font-size: 7px;">¡IMPORTANTE LEER!</span></div>
                <ul class="clausulas" style="padding-left: 12px; margin: 3px 0 0;">
                    @foreach (array_filter(array_map('trim', explode("\n", $terminos))) as $t)<li>{{ $t }}</li>@endforeach
                </ul>
            </td>
            <td style="width: 170px; padding-left: 8px;">
                <table>
                    <tr><td><b>TOTAL</b></td><td style="text-align: right;">{{ dinero($orden->total) }}</td></tr>
                    <tr><td><b>ANTICIPO</b></td><td style="text-align: right;">{{ dinero($orden->pagado) }}</td></tr>
                    <tr><td><b>RESTA</b></td><td style="text-align: right; font-weight: bold;">{{ dinero($orden->saldo) }}</td></tr>
                </table>
                <div class="firma">ACEPTO TÉRMINOS Y CONDICIONES</div>
                <div class="firma">FIRMA DE ENTREGA</div>
            </td>
        </tr>
    </table>

    <table class="mt sin-borde">
        <tr>
            <td style="width: 90px;"><img src="{{ $qr }}" style="width: 85px; height: 85px;"></td>
            <td style="vertical-align: middle;">
                <div style="font-weight: bold; font-size: 11px;">Escanea el código para ver el estatus de tu reparación</div>
                <div style="font-size: 8px; color: #555;">{{ $enlace }}</div>
            </td>
        </tr>
    </table>
</body>
</html>

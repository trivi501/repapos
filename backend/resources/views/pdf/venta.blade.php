<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->folio }}</title>
    <style>
        /* ticket de 80 mm de ancho (dompdf) */
        @page { margin: 4mm; }
        body { font-family: "DejaVu Sans Mono", monospace; font-size: 9px; color: #000; }
        .centro { text-align: center; }
        .linea { border-top: 1px dashed #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .der { text-align: right; white-space: nowrap; }
        .grande { font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="centro">
        @if ($logo)<img src="{{ $logo }}" style="max-height: 55px; max-width: 150px;"><br>@endif
        <div class="grande">{{ $venta->taller->nombre }}</div>
        @if ($venta->sucursal->direccion)<div>{{ $venta->sucursal->direccion }}</div>@endif
        @if ($venta->sucursal->telefono)<div>Tel. {{ $venta->sucursal->telefono }}</div>@endif
    </div>
    <div class="linea"></div>
    <div>Venta N° {{ str_pad($venta->folio, 5, '0', STR_PAD_LEFT) }}</div>
    <div>{{ $venta->created_at->format('d/m/Y H:i') }}{{ $venta->user ? ' · ' . $venta->user->name : '' }}</div>
    @if ($venta->cliente_nombre)<div>{{ $venta->tipo === 'LOCATARIO' ? 'Locatario' : 'Cliente' }}: {{ $venta->cliente_nombre }}</div>@endif
    @if ($venta->estatus === 'CANCELADA')<div class="grande centro">*** CANCELADA ***</div>@endif
    <div class="linea"></div>
    <table>
        @foreach ($venta->detalles as $d)
            <tr><td colspan="2">{{ $d->nombre }}</td></tr>
            <tr><td>&nbsp; {{ $d->cantidad }} x {{ dinero($d->precio) }}</td><td class="der">{{ dinero($d->importe) }}</td></tr>
        @endforeach
    </table>
    <div class="linea"></div>
    <table>
        @if ((float) $venta->descuento > 0)
            <tr><td>Subtotal</td><td class="der">{{ dinero($venta->subtotal) }}</td></tr>
            <tr><td>Descuento</td><td class="der">-{{ dinero($venta->descuento) }}</td></tr>
        @endif
        <tr class="grande"><td>TOTAL</td><td class="der">{{ dinero($venta->total) }}</td></tr>
        <tr><td>{{ ucfirst(mb_strtolower($venta->metodo)) }}</td><td class="der">{{ $venta->recibido !== null ? dinero($venta->recibido) : '' }}</td></tr>
        @if ($venta->recibido !== null)<tr><td>Cambio</td><td class="der">{{ dinero($venta->cambio) }}</td></tr>@endif
    </table>
    <div class="linea"></div>
    <div class="centro">{!! nl2br(e($pie)) !!}</div>
</body>
</html>

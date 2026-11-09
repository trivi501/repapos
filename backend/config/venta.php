<?php

// Datos del producto que se vende a otros talleres. Se cambian en el .env sin tocar código.
return [
    'producto' => env('VENTA_PRODUCTO', 'RepaPOS'),
    'dias_prueba' => (int) env('VENTA_DIAS_PRUEBA', 15),
    'precio_mensual' => (float) env('VENTA_PRECIO_MENSUAL', 299),
    'precio_anual' => (float) env('VENTA_PRECIO_ANUAL', 2990),
    'moneda' => env('VENTA_MONEDA', 'MXN'),
    // WhatsApp al que te escriben los talleres para contratar (10 dígitos o con lada)
    'whatsapp' => env('VENTA_WHATSAPP', ''),
    // Cómo te pagan (se muestra en la pantalla de suscripción). Usa \n para saltos de línea.
    'datos_pago' => str_replace('\n', "\n", env('VENTA_DATOS_PAGO', "Transferencia o depósito.\nEscríbenos por WhatsApp para darte los datos.")),
];

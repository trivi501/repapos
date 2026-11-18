<?php
/**
 * Parte PHP de deploy/servidor-local.mjs (probar RepaPOS como en el servidor, en esta PC y sin nginx):
 * Laravel en /api y las fotos en /storage. Node lo arranca solo y le pasa APP_ENV=production y APP_DEBUG=false.
 * También sirve el Angular compilado si se usa directo (php -S 127.0.0.1:8091 deploy/servidor-local.php),
 * pero el servidor de PHP en Windows corta descargas simultáneas: para el navegador conviene el de Node.
 */
$raiz = dirname(__DIR__);
$web = $raiz.'/frontend/dist/frontend/browser';
$ruta = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// archivos ocultos (.env, .git…): nunca
if (preg_match('#/\.(?!well-known)#', $ruta)) {
    http_response_code(403);
    return true;
}

// API de Laravel
if ($ruta === '/api' || str_starts_with($ruta, '/api/')) {
    // el servidor de PHP no le pasa a Laravel las variables de la consola: se copian para que manden sobre el .env
    foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'FRONTEND_URL'] as $clave) {
        if (($valor = getenv($clave)) !== false) {
            $_SERVER[$clave] = $_ENV[$clave] = $valor;
        }
    }
    $_SERVER['SCRIPT_FILENAME'] = $raiz.'/backend/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['DOCUMENT_ROOT'] = $raiz.'/backend/public';
    chdir($raiz.'/backend/public');
    require $raiz.'/backend/public/index.php';
    return true;
}

// fotos de las reparaciones: solo se entregan como archivos
if (str_starts_with($ruta, '/storage/')) {
    $archivo = realpath($raiz.'/backend/storage/app/public/'.substr($ruta, 9));
    $base = realpath($raiz.'/backend/storage/app/public');
    if ($archivo && $base && str_starts_with($archivo, $base) && is_file($archivo)) {
        header('Content-Type: '.(mime_content_type($archivo) ?: 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        readfile($archivo);
    } else {
        http_response_code(404);
    }
    return true;
}

// Angular: el archivo si existe; cualquier otra ruta la resuelve index.html
$archivo = realpath($web.$ruta);
$base = realpath($web);
if (! $base) {
    http_response_code(500);
    echo 'Falta compilar el frontend: npm --prefix frontend run build';
    return true;
}
if ($ruta === '/' || ! $archivo || ! str_starts_with($archivo, $base) || ! is_file($archivo)) {
    $archivo = $base.'/index.html';
}
$tipos = ['js' => 'text/javascript', 'css' => 'text/css', 'html' => 'text/html; charset=utf-8', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'ico' => 'image/x-icon', 'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
    'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8'];
$ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
header('Content-Type: '.($tipos[$ext] ?? (mime_content_type($archivo) ?: 'application/octet-stream')));
header('X-Content-Type-Options: nosniff');
header($ext === 'html' ? 'Cache-Control: no-cache' : 'Cache-Control: public, max-age=31536000, immutable');
readfile($archivo);
return true;

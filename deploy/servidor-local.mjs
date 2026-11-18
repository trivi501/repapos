// Probar RepaPOS como en el servidor, en esta PC y sin nginx (igual que deploy/nginx.conf.plantilla):
// - Node sirve el Angular compilado (frontend/dist) y cualquier otra ruta la resuelve index.html.
// - /api y /storage se pasan a PHP (deploy/servidor-local.php), que corre Laravel en modo producción.
//
//   node deploy/servidor-local.mjs            (abre http://localhost:8090)
//
// Arranca PHP solo en el puerto 8091 con APP_ENV=production y APP_DEBUG=false; al cerrar Node, se cierra PHP.
import { createServer, request } from 'node:http';
import { spawn } from 'node:child_process';
import { readFile, stat } from 'node:fs/promises';
import { dirname, extname, join, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const PUERTO = Number(process.env.PUERTO ?? 8090);
const PUERTO_PHP = PUERTO + 1;
const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const WEB = join(RAIZ, 'frontend', 'dist', 'frontend', 'browser');
const PHP = process.env.PHP ?? 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
const URL_SITIO = `http://localhost:${PUERTO}`;

const TIPOS = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.ico': 'image/x-icon', '.webp': 'image/webp',
  '.woff2': 'font/woff2', '.txt': 'text/plain; charset=utf-8', '.webmanifest': 'application/manifest+json',
};

// Laravel en modo producción (las variables de la consola mandan sobre el .env)
const php = spawn(PHP, ['-S', `127.0.0.1:${PUERTO_PHP}`, join('deploy', 'servidor-local.php')], {
  cwd: RAIZ,
  env: { ...process.env, APP_ENV: 'production', APP_DEBUG: 'false', APP_URL: URL_SITIO, FRONTEND_URL: URL_SITIO },
  stdio: ['ignore', 'ignore', 'inherit'],
});
php.on('exit', (codigo) => { console.error(`PHP se cerró (${codigo}).`); process.exit(1); });
for (const s of ['SIGINT', 'SIGTERM', 'exit']) process.on(s, () => { try { php.kill(); } catch {} if (s !== 'exit') process.exit(0); });

function aPhp(req, res) {
  const p = request({ host: '127.0.0.1', port: PUERTO_PHP, method: req.method, path: req.url, headers: req.headers }, (r) => {
    res.writeHead(r.statusCode ?? 502, r.headers);
    r.pipe(res);
  });
  p.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end('PHP no responde'); });
  req.pipe(p);
}

async function archivo(req, res) {
  const ruta = decodeURIComponent(new URL(req.url, URL_SITIO).pathname);
  let destino = normalize(join(WEB, ruta));
  if (!destino.startsWith(WEB + sep) && destino !== WEB) { res.writeHead(403); return res.end(); }
  try {
    if (!(await stat(destino)).isFile()) throw new Error();
  } catch {
    destino = join(WEB, 'index.html');   // ruta de Angular
  }
  try {
    const datos = await readFile(destino);
    const ext = extname(destino).toLowerCase();
    res.writeHead(200, {
      'Content-Type': TIPOS[ext] ?? 'application/octet-stream',
      'Cache-Control': ext === '.html' ? 'no-cache' : 'public, max-age=31536000, immutable',
      'X-Content-Type-Options': 'nosniff',
    });
    res.end(datos);
  } catch {
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Falta compilar el frontend: npm --prefix frontend run build');
  }
}

createServer((req, res) => {
  const ruta = req.url ?? '/';
  if (/\/\.(?!well-known)/.test(ruta)) { res.writeHead(403); return res.end(); }   // .env, .git…
  if (ruta === '/api' || ruta.startsWith('/api/') || ruta.startsWith('/storage/')) return aPhp(req, res);
  archivo(req, res);
}).listen(PUERTO, '127.0.0.1', () => console.log(`RepaPOS (modo producción) en ${URL_SITIO}`));

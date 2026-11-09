# RepaPOS

Sistema para talleres de reparación de celulares: backend en Laravel (API REST, Sanctum y Spatie Permission),
frontend en Angular 22 y base de datos MySQL. Atiende a varios talleres, cada uno con sus sucursales y sus datos separados.

Incluye todo lo que venía en RepaPOS (`C:\laragon\www\taller\telefonos-web`), migrado con sus datos.

## Estructura

```
sistema taller/
├── backend/    Laravel API
├── frontend/   Angular SPA
└── deploy/     Scripts y guía para el VPS
```

## Requisitos

- PHP 8.3+, Composer
- Node 20+, npm
- MySQL (Laragon)

## Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link   # sirve las fotos de las reparaciones desde /storage
php artisan serve --port=8000
```

`migrate --seed` crea los roles y un "Taller de pruebas" con estos usuarios (contraseña `password`):

| Rol           | Correo                         |
|---------------|--------------------------------|
| Administrador | admin@tallercelulares.test     |
| Recepcionista | recepcion@tallercelulares.test |
| Tecnico       | tecnico@tallercelulares.test   |

Para crear un taller real con su administrador (`--superadmin` lo hace dueño del sistema, `--cortesia` le quita el vencimiento):

```bash
php artisan app:crear-taller --superadmin --cortesia
```

## Frontend

```bash
cd frontend
npm install
npm start   # http://localhost:4200
```

## Módulos

| Menú | Pantallas |
|------|-----------|
| **Locatarios** | Teléfonos (con exportar a Excel), resumen por locatario con estado de cuenta, saldos a favor |
| **Clientes** | Reparaciones (secciones 01–08, ficha, comprobante imprimible y PDF), clientes, equipos |
| **Ventas** | Punto de venta (a crédito para locatarios, devoluciones, cancelación), ventas del día, inventario con kárdex y traspasos |
| **Caja** | Corte de caja (solo cuenta efectivo) e historial, salidas de dinero |
| **Ajustes** | Configuración y textos de WhatsApp, logo, usuarios, sucursales, roles, suscripción |
| **Sistema** | Panel de talleres (solo el dueño del sistema) |

Páginas públicas, sin iniciar sesión: `/` (venta), `/registro`, `/consulta` (por folio) y `/orden/:token` (enlace del comprobante o de WhatsApp).

## Permisos

Los permisos se validan en el backend (middleware `permission:` en `routes/api.php`) y en el frontend (guards y botones).

| Rol | Puede |
|-----|-------|
| Superadmin | Todo, incluido el panel de talleres y modificar roles. Los roles son globales, por eso solo él los cambia |
| Administrador | Todo su taller |
| Empleado | Lo mismo que un empleado de RepaPOS: opera todo, menos usuarios, sucursales, respaldo y cancelar ventas |
| Recepcionista | Recepción, cobros y ventas; no elimina |
| Tecnico | Ve y actualiza órdenes y teléfonos; no cobra |

## Separación de datos

Los modelos con `DelTaller` y `DeSucursal` filtran solos por el taller y la sucursal del usuario, también al buscar por id en una URL.
Las reglas `exists` de validación **no** pasan por esos filtros: al validar una referencia hay que usar
`Rule::exists(...)->where('taller_id', ...)`. Las pruebas de esto están en `backend/tests/Feature/RepaposTest.php`.

```bash
cd backend
php artisan test
```

## Migración desde RepaPOS

```bash
php artisan app:importar-repapos --simular         # copia y verifica; al final deshace todo
php artisan app:importar-repapos                   # importación real
```

Lee la base `telefonos` sin modificarla, compara cantidades, dinero y la deuda de cada locatario, y deshace todo si algo no cuadra.
No importa dos veces. PIN y patrones se descifran con el `APP_KEY` de RepaPOS (`--env=ruta/.env`); si no es el original se detiene,
y `--sin-descifrar` importa sin ese dato dejando una nota en la orden.

También sigue disponible `php artisan app:importar-xml` para la versión de escritorio.

## Tareas programadas

`app:respaldo` (23:30) y `model:prune` (borradores de fotos con más de 24 h) requieren el programador de Laravel:
`php artisan schedule:run` cada minuto, con cron o el Programador de tareas de Windows.

## Fotos desde el celular (Cámara QR)

El QR apunta a `MOBILE_UPLOAD_URL` (en `backend/.env`). Para que el celular pueda abrirlo, usa la IP de la computadora en la red local:

```
MOBILE_UPLOAD_URL=http://192.168.1.50:8000
```

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

## Producción

Para el VPS de Hostinger sigue [deploy/INSTALAR-VPS.md](deploy/INSTALAR-VPS.md). Angular y la API van en el mismo dominio, por eso `environment.ts` usa `/api`.

- `FRONTEND_URL`: dirección de Angular; va en los enlaces que recibe el cliente por WhatsApp y en el QR del comprobante.
- `APP_URL`: dirección de la API; va en los enlaces públicos a los PDF.
- `CORS_ALLOWED_ORIGINS`: debe incluir la dirección de Angular.
- `VENTA_*`: precios, días de prueba y WhatsApp de contacto del producto.
- **No corras `php artisan db:seed` sin `--class`**: crea usuarios de prueba con contraseña `password`. En producción usa `--class=RolesAndPermissionsSeeder`.
- **Guarda `APP_KEY` en un lugar seguro**: sin esa clave no se pueden leer los PIN y patrones guardados.

# Subir RepaPOS a un VPS de Hostinger (repapos.tech)

Quedará todo en un solo dominio: `https://repapos.tech` es Angular y `https://repapos.tech/api` es Laravel.
El VPS necesita **Ubuntu 24.04**, sin nada más instalado. Basta con el plan más chico (KVM 1), porque Angular se compila en tu PC.

> Los comandos marcados **PC** van en PowerShell, en la carpeta del proyecto. Los marcados **VPS** van en la sesión SSH.
> Donde diga `IP_DEL_VPS` pon la IP que aparece en hPanel.

## 1. Preparar el VPS en hPanel

1. **VPS → Sistema operativo:** elige la plantilla simple de **Ubuntu 24.04** (sin panel). Reinstalar borra lo que tenga el VPS.
2. **VPS → Información general:** anota la IP y define la contraseña de `root`.
3. **VPS → Seguridad → Firewall:** si tienes reglas activas, abre los puertos 22, 80 y 443.

## 2. Apuntar el dominio

Hoy `repapos.tech` está en los DNS de parqueo de Hostinger. En **hPanel → Dominios → repapos.tech → DNS / Nameservers**:

| Tipo | Nombre | Apunta a | TTL |
|------|--------|----------|-----|
| A | `@` | `IP_DEL_VPS` | 300 |
| A | `www` | `IP_DEL_VPS` | 300 |

Borra cualquier otro registro `A`, `AAAA` o `CNAME` de `@` y `www`. Comprueba que ya responda tu IP (suele tardar de minutos a un par de horas):

**PC**
```bash
nslookup repapos.tech 8.8.8.8
```

## 3. Crear los paquetes

**PC** (MySQL de Laragon encendido)
```bash
powershell -ExecutionPolicy Bypass -File deploy\empaquetar.ps1 -ConDatos
```

Deja en la carpeta `paquetes` dos archivos:

- `repapos-FECHA.tar.gz`: el código.
- `repapos-datos-FECHA.tar.gz`: tu base de datos, las fotos, los logos y tu `APP_KEY`. **Trae información de tus clientes**: no lo compartas.

Si quieres empezar con la base vacía, quita `-ConDatos`.

## 4. Subirlos

Cambia `FECHA` por la que tengan tus archivos.

**PC**
```bash
scp paquetes\repapos-FECHA.tar.gz paquetes\repapos-datos-FECHA.tar.gz root@IP_DEL_VPS:/root/
```

## 5. Instalar

**PC**
```bash
ssh root@IP_DEL_VPS
```

**VPS**
```bash
mkdir -p /root/repapos && tar xzf /root/repapos-FECHA.tar.gz -C /root/repapos
```

**VPS** (pon tu correo: Let's Encrypt lo usa para avisos del certificado)
```bash
bash /root/repapos/deploy/instalar.sh --dominio repapos.tech --correo TU_CORREO --datos /root/repapos-datos-FECHA.tar.gz
```

El script instala nginx, MySQL y PHP 8.3, crea la base con una contraseña al azar, carga tus datos, saca el certificado HTTPS,
activa el firewall y programa los respaldos. Tarda unos minutos y al final comprueba que el sitio responda.

Si el dominio todavía no apunta al VPS, instala sin HTTPS y te avisa. Cuando el DNS esté listo, vuelve a correr el mismo comando
sin `--datos`: no borra nada.

**Con `--datos`:**

- Conserva tu `APP_KEY`. Sin ella no se podrían leer los PIN y patrones guardados.
- Borra del VPS el paquete de datos cuando termina de cargarlo. Solo lo carga si la base está vacía.
- Bloquea las cuentas de prueba (`*@tallercelulares.test` y `demo@local.test`), porque sus contraseñas no son seguras.
- Cierra todas las sesiones, así que hay que volver a entrar.

**Sin `--datos`,** crea tu taller y tu usuario de dueño al terminar:

**VPS**
```bash
cd /var/www/repapos/backend && sudo -u www-data php artisan app:crear-taller --superadmin --cortesia
```

## 6. Comprobar

1. Abre `https://repapos.tech`: debe verse la página de venta con el candado de HTTPS.
2. Entra con tu cuenta y revisa tus teléfonos, tus locatarios y tu logo.
3. Abre una reparación con fotos y descarga su PDF.
4. Prueba la Cámara QR con el celular, usando datos móviles.
5. Borra de tu PC el paquete de datos cuando todo esté bien.

Si tu contraseña es débil o la has compartido, cámbiala en **Mi perfil**. A partir de ahora el sistema está en internet.

## 7. Datos de venta

**VPS**
```bash
nano /var/www/repapos/backend/.env
```

Ajusta `VENTA_WHATSAPP`, los precios y los días de prueba, y luego:

**VPS**
```bash
cd /var/www/repapos/backend && sudo -u www-data php artisan config:cache
```

La página de registro queda abierta: cualquiera puede crear un taller de prueba desde `repapos.tech/registro`.

## Subir cambios después

**PC**
```bash
powershell -ExecutionPolicy Bypass -File deploy\empaquetar.ps1
```

**PC**
```bash
scp paquetes\repapos-FECHA.tar.gz root@IP_DEL_VPS:/root/
```

**VPS**
```bash
rm -rf /root/repapos && mkdir -p /root/repapos && tar xzf /root/repapos-FECHA.tar.gz -C /root/repapos && bash /root/repapos/deploy/actualizar.sh
```

`actualizar.sh` respalda la base en `/var/backups/repapos`, pone el sistema en mantenimiento, aplica el código y las migraciones, y lo vuelve a abrir.
No toca `.env`, las fotos ni los logos.

**No uses `-ConDatos` para actualizar:** en producción los datos buenos ya son los del servidor.

## Respaldos

`app:respaldo` corre todos los días a las 23:30 y guarda los datos en `/var/www/repapos/backend/storage/app/respaldos`.
**Esos archivos no incluyen las fotos y se quedan en el mismo VPS**, así que no te protegen si el servidor falla.
Activa también los respaldos o las instantáneas del VPS en hPanel, y guarda tu `APP_KEY` en un lugar seguro fuera del servidor.

## Si algo falla

**VPS**
```bash
tail -n 50 /var/www/repapos/backend/storage/logs/laravel-$(date +%F).log
```

**VPS**
```bash
tail -n 50 /var/log/nginx/error.log
```

**VPS**
```bash
systemctl status nginx php8.3-fpm mysql --no-pager
```

| Síntoma | Causa probable |
|---------|----------------|
| El script dice que el dominio no apunta al VPS | El DNS aún no se propaga: revisa el paso 2 y vuelve a correrlo |
| `certbot` falla | Puertos 80 o 443 cerrados en el firewall de hPanel, o demasiados intentos: espera una hora |
| "Sesión expirada" o pantalla en blanco tras actualizar | Recarga la página con Ctrl+F5 |
| Error 413 al subir fotos | Revisa `client_max_body_size` en `/etc/nginx/sites-available/repapos` |
| Te quedas sin SSH | Usa la terminal del navegador en hPanel y corre `ufw allow 22/tcp` |

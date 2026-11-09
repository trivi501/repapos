# Subir RepaPOS a un VPS de Hostinger (repapos.tech)

Quedará todo en un solo dominio: `https://repapos.tech` es Angular y `https://repapos.tech/api` es Laravel.

**Se puede instalar junto a otras apps.** RepaPOS queda en `/var/www/repapos`, con su propio usuario de Linux, su proceso de PHP, su base de datos y su sitio de nginx.

> Los comandos marcados **PC** van en PowerShell, en la carpeta del proyecto. Los marcados **VPS** van en la sesión SSH.
> Donde diga `IP_DEL_VPS` pon la IP que aparece en hPanel.

## Si el VPS ya tiene otras apps

- **No reinstales el sistema operativo en hPanel:** borraría todo.
- **Crea una instantánea (snapshot) del VPS en hPanel** antes de empezar. Es tu forma de volver atrás.
- `instalar.sh` no quita sitios de nginx, no activa el firewall, no cambia la configuración global de PHP o MySQL, ni actualiza paquetes ya instalados.
- Sí hace una recarga suave de nginx y PHP, que no corta a las otras apps.

### Revisa el VPS antes

Este comando solo lee información y no cambia nada. Pégalo en la sesión SSH:

**VPS**
```bash
. /etc/os-release; echo "SO: $PRETTY_NAME"; echo "--- puertos web"; ss -ltnp | grep -E ':(80|443)\s' | awk '{print $4, $6}'; echo "--- sitios nginx"; ls /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null; echo "--- PHP"; ls /etc/php 2>/dev/null | tr '\n' ' '; echo; php -v 2>/dev/null | head -1; echo "--- base de datos"; mysql --version 2>/dev/null; mysql -e 'SELECT VERSION()' 2>&1 | tail -1; echo "--- paneles"; ls -d /usr/local/psa /usr/local/cpanel /usr/local/hestia /usr/local/CyberCP /home/clp /www/server/panel 2>/dev/null; docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null; echo "--- apps"; ls /var/www 2>/dev/null; echo "--- memoria y disco"; free -h | sed -n 2p; df -h / | tail -1
```

| Si ves... | Qué significa |
|-----------|---------------|
| `nginx` en los puertos 80 y 443, o nada | Se puede instalar directo |
| `apache2`, `litespeed`, `docker-proxy` u otro en esos puertos | `instalar.sh` se detiene. Hay que adaptar la instalación a ese servidor web |
| Un panel (CloudPanel, CyberPanel, Plesk, etc.) | `instalar.sh` se detiene. El sitio se crea desde el panel |
| Ubuntu 22.04 o Debian 12 sin PHP 8.3 | Hay que agregar un repositorio de PHP. Revísalo primero, porque el cambio afecta a todo el servidor |
| `Access denied` en la base de datos | Antes de instalar, crea `/root/.my.cnf` con `[client]`, `user=root` y `password="TU_CLAVE"`, con permisos 600. Solo se usa para crear la base: bórralo al terminar |
| Menos de 500 MB de memoria libre | Conviene subir de plan |

## Si el VPS está vacío

En **hPanel → VPS → Sistema operativo** elige Ubuntu 24.04 sin panel. Después activa el firewall de hPanel con los puertos 22, 80 y 443 abiertos.

## 1. Apuntar el dominio

Compara lo que responde `nslookup repapos.tech 8.8.8.8` con la IP del VPS (`hostname -I`). Si coinciden, este paso ya está hecho. Si no, en **hPanel → Dominios → repapos.tech → DNS / Nameservers**:

| Tipo | Nombre | Apunta a | TTL |
|------|--------|----------|-----|
| A | `@` | `IP_DEL_VPS` | 300 |
| A | `www` | `IP_DEL_VPS` | 300 |

Borra cualquier otro registro `A`, `AAAA` o `CNAME` de `@` y `www`. Esto solo afecta a `repapos.tech`, no a los dominios de tus otras apps.

Comprueba que ya responda tu IP (suele tardar de minutos a un par de horas):

**PC**
```bash
nslookup repapos.tech 8.8.8.8
```

## 2. Bajar el código con Git

El repo es privado y GitHub no acepta tu contraseña normal por Git. Necesitas un token de solo lectura:

1. En GitHub ve a **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. En **Repository access** elige solo `trivi501/repapos`.
3. En **Permissions → Contents** elige **Read-only**.
4. Ponle una caducidad corta y cópialo. No lo compartas ni lo pongas dentro de la dirección del repo.

Clónalo en `/root`, **no en `/var/www`**:

**VPS**
```bash
git clone https://github.com/trivi501/repapos /root/repapos
```

Cuando lo pida, escribe `trivi501` como usuario y pega el token como contraseña. La contraseña no se ve al escribirla, y Git no la guarda: te la pedirá en cada `git pull`.

## 3. Subir tus datos

Tus clientes, fotos, logos y `APP_KEY` **no van en Git**. Van en un paquete aparte.

**PC** (MySQL de Laragon encendido)
```bash
powershell -ExecutionPolicy Bypass -File deploy\empaquetar.ps1 -ConDatos
```

De lo que deja en la carpeta `paquetes`, sube solo el de datos, cambiando `FECHA` por la de tu archivo. **Trae información de tus clientes**: no lo compartas.

**PC**
```bash
scp paquetes\repapos-datos-FECHA.tar.gz root@IP_DEL_VPS:/root/
```

Si quieres empezar con la base vacía, sáltate este paso y quita `--datos` del siguiente comando.

## 4. Instalar

**VPS** (pon tu correo: Let's Encrypt lo usa para avisos del certificado)
```bash
bash /root/repapos/deploy/instalar.sh --dominio repapos.tech --correo TU_CORREO --datos /root/repapos-datos-FECHA.tar.gz
```

Primero revisa el servidor, y si encuentra algo que pueda chocar se detiene sin haber cambiado nada. Después instala solo lo que falte, crea la base con una contraseña al azar, carga tus datos, saca el certificado HTTPS de este dominio y programa los respaldos. Al final comprueba que el sitio responda.

Si el dominio todavía no apunta al VPS, instala sin HTTPS y te avisa. Cuando el DNS esté listo, vuelve a correr el mismo comando sin `--datos`: no borra nada.

**Con `--datos`:**

- Conserva tu `APP_KEY`. Sin ella no se podrían leer los PIN y patrones guardados.
- Borra del VPS el paquete de datos cuando termina de cargarlo. Solo lo carga si la base está vacía.
- Bloquea las cuentas de prueba (`*@tallercelulares.test` y `demo@local.test`), porque sus contraseñas no son seguras.
- Cierra todas las sesiones de RepaPOS, así que hay que volver a entrar. Cada vez que corras `instalar.sh` vuelve a cerrarlas.

**Sin `--datos`,** al terminar el script te muestra el comando para crear tu taller y tu usuario de dueño.

## 5. Comprobar

1. Abre `https://repapos.tech`: debe verse la página de venta con el candado de HTTPS.
2. **Abre tus otras apps y confirma que siguen funcionando.**
3. Entra a RepaPOS con tu cuenta y revisa tus teléfonos, tus locatarios y tu logo.
4. Abre una reparación con fotos y descarga su PDF.
5. Prueba la Cámara QR con el celular, usando datos móviles.
6. Borra de tu PC el paquete de datos cuando todo esté bien.

Si tu contraseña es débil o la has compartido, cámbiala en **Mi perfil**. A partir de ahora el sistema está en internet.

## 6. Datos de venta

**VPS**
```bash
nano /var/www/repapos/backend/.env
```

Ajusta `VENTA_WHATSAPP`, los precios y los días de prueba, y luego aplica el cambio (cambia `8.3` si el instalador eligió otra versión de PHP):

**VPS**
```bash
cd /var/www/repapos/backend && sudo -u repapos php8.3 artisan config:cache
```

La página de registro queda abierta: cualquiera puede crear un taller de prueba desde `repapos.tech/registro`.

## Subir cambios después

**Compila Angular antes de subir.** Si no, el sitio se quedará con la pantalla anterior.

**PC**
```bash
npm --prefix frontend run build
```

**PC**
```bash
git add -A
```

**PC**
```bash
git commit -m "Describe el cambio"
```

**PC**
```bash
git push
```

**VPS**
```bash
cd /root/repapos && git pull && bash deploy/actualizar.sh
```

`actualizar.sh` respalda la base en `/var/backups/repapos`, pone RepaPOS en mantenimiento, aplica el código y las migraciones, y lo vuelve a abrir.
No toca `.env`, las fotos, los logos ni las otras apps.

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

| Síntoma | Causa probable |
|---------|----------------|
| El script dice que el dominio no apunta al VPS | El DNS aún no se propaga: revisa el paso 1 y vuelve a correrlo |
| `certbot` falla | Puertos 80 o 443 cerrados en el firewall de hPanel, o demasiados intentos: espera una hora |
| "Sesión expirada" o pantalla en blanco tras actualizar | Recarga la página con Ctrl+F5 |
| Error 413 al subir fotos | Revisa `client_max_body_size` en `/etc/nginx/sites-available/repapos` |
| Otra app dejó de funcionar | Retira RepaPOS de nginx con `rm /etc/nginx/sites-enabled/repapos && systemctl reload nginx` y revisa; si sigue igual, restaura la instantánea |

#!/usr/bin/env bash
# Instala RepaPOS en un VPS con Debian o Ubuntu, junto a las apps que ya tenga. Se puede volver a correr:
# no borra datos ni cambia las claves.
#
#   bash /var/www/repapos/deploy/instalar.sh --dominio repapos.tech --correo tu@correo.com --datos /root/repapos-datos-XXXX.tar.gz
#
# --dominio  Dominio ya apuntando al VPS (o la IP, solo para probar: sin HTTPS).
# --correo   Correo para los avisos de Let's Encrypt.
# --datos    Opcional. Paquete con tus datos, hecho con exportar-datos.ps1. Solo se carga si la base está vacía.
#
# Lo que NO hace, para no afectar a las otras apps: quitar sitios de nginx, activar el firewall,
# cambiar la configuración global de PHP o de MySQL, actualizar paquetes ya instalados ni instalar
# MySQL si ya hay un servidor de base de datos.
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

DOMINIO=''
CORREO=''
DATOS=''
while [ $# -gt 0 ]; do
  case "$1" in
    --dominio) DOMINIO="${2:-}"; shift 2 ;;
    --correo) CORREO="${2:-}"; shift 2 ;;
    --datos) DATOS="${2:-}"; shift 2 ;;
    *) fallo "Opción desconocida: $1" ;;
  esac
done

exigir_root
exigir_codigo
[ -n "$DOMINIO" ] || fallo 'Falta --dominio.'
[ -z "$DATOS" ] || [ -f "$DATOS" ] || fallo "No existe el paquete de datos: $DATOS"
command -v apt-get >/dev/null || fallo 'Este script es para Debian o Ubuntu.'

ES_IP=0
[[ "$DOMINIO" =~ ^[0-9.]+$ ]] && ES_IP=1

# ---------------------------------------------------------------------------
paso 'Revisando el servidor (todavía no cambio nada)'

for panel in /usr/local/psa /usr/local/cpanel /usr/local/hestia /usr/local/vesta /usr/local/CyberCP /home/clp /www/server/panel; do
  [ ! -e "$panel" ] || fallo "Este VPS se administra con un panel ($panel). Crea el sitio desde el panel: este script podría chocar con él."
done

OCUPADO="$(ss -ltnpH 2>/dev/null | awk '$4 ~ /:(80|443)$/' | grep -v '"nginx"' || true)"
[ -z "$OCUPADO" ] || fallo "Otro programa que no es nginx usa el puerto 80 o 443, así que este script no puede instalar aquí:
$OCUPADO"

if command -v nginx >/dev/null; then
  nginx -t 2>/dev/null || fallo 'La configuración actual de nginx ya tiene errores. Corrígelos antes (revisa: nginx -t).'
  if [ -d /etc/nginx ]; then
    # -R: en sites-enabled suelen ser enlaces, y -r no los sigue
    OTRO_SITIO="$(grep -RlE "server_name[^;]*[[:space:]]$DOMINIO([[:space:];]|$)" /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null | grep -v '/repapos$' || true)"
    [ -z "$OTRO_SITIO" ] || fallo "$DOMINIO ya está configurado en: $OTRO_SITIO"
  fi
fi

if [ ! -f "$APP/backend/.env" ]; then
  if command -v mysql >/dev/null; then
    mysql -e 'SELECT 1' >/dev/null 2>&1 || fallo 'Ya hay base de datos, pero root no puede entrar sin contraseña.
Crea /root/.my.cnf con [client], user=root y password="TU_CLAVE" (chmod 600), y vuelve a correr este script.
Solo se necesita para crear la base: al terminar lo puedes borrar.'
  fi
  if command -v mysql >/dev/null && [ -n "$(mysql -N -e "SHOW DATABASES LIKE 'repapos'")" ]; then
    fallo 'Ya existe una base llamada "repapos" y RepaPOS no está instalado aquí. No la voy a tocar.'
  fi
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -q

# Prefiere la versión de PHP que ya se haya usado, luego una instalada y al final una disponible.
if [ -z "$PHP_V" ]; then
  for v in 8.3 8.4 8.5; do
    if dpkg-query -W -f='${Status}' "php$v-fpm" 2>/dev/null | grep -q 'install ok installed'; then PHP_V="$v"; break; fi
  done
fi
if [ -z "$PHP_V" ]; then
  for v in 8.3 8.4 8.5; do
    if [ -n "$(apt-cache policy "php$v-fpm" 2>/dev/null | awk '/Candidate:/ && $2 != "(none)"')" ]; then PHP_V="$v"; break; fi
  done
fi
[ -n "$PHP_V" ] || fallo 'RepaPOS necesita PHP 8.3 o mayor y este sistema no lo ofrece.
En Ubuntu 22.04 o Debian 12 hay que agregar antes el repositorio de PHP de Ondřej Surý. Revisa que tus otras apps no se afecten.'
echo "PHP $PHP_V"

# ---------------------------------------------------------------------------
paso 'Paquetes que falten (no actualiza los que ya están)'
PAQUETES=(rsync unzip curl openssl sudo cron
  "php$PHP_V-fpm" "php$PHP_V-cli" "php$PHP_V-mysql" "php$PHP_V-mbstring" "php$PHP_V-xml"
  "php$PHP_V-curl" "php$PHP_V-zip" "php$PHP_V-intl" "php$PHP_V-bcmath" "php$PHP_V-gd")
command -v nginx >/dev/null || PAQUETES+=(nginx)
command -v mysql >/dev/null || PAQUETES+=(mysql-server)
command -v composer >/dev/null || PAQUETES+=(composer)
if [ "$ES_IP" -eq 0 ] && ! command -v certbot >/dev/null; then PAQUETES+=(certbot python3-certbot-nginx); fi
apt-get install -y -q --no-upgrade "${PAQUETES[@]}"

# ---------------------------------------------------------------------------
paso 'Usuario y proceso de PHP propios'
id "$USUARIO" >/dev/null 2>&1 || useradd --system --user-group --no-create-home --shell /usr/sbin/nologin "$USUARIO"

USUARIO_NGINX="$(awk '$1 == "user" { gsub(";", "", $2); print $2; exit }' /etc/nginx/nginx.conf)"
USUARIO_NGINX="${USUARIO_NGINX:-www-data}"
usermod -aG "$USUARIO" "$USUARIO_NGINX"   # para entregar las fotos públicas

cat > "/etc/php/$PHP_V/fpm/pool.d/repapos.conf" <<POOL
[repapos]
user = $USUARIO
group = $USUARIO
listen = /run/php/repapos.sock
listen.owner = $USUARIO_NGINX
listen.group = $USUARIO_NGINX
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 64M
php_admin_value[memory_limit] = 256M
php_admin_value[date.timezone] = America/Mexico_City
POOL
"php-fpm$PHP_V" -t
systemctl enable --now "php$PHP_V-fpm" nginx cron
systemctl reload "php$PHP_V-fpm"
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -S /run/php/repapos.sock ] && break; sleep 1; done
[ -S /run/php/repapos.sock ] || fallo "PHP no creó /run/php/repapos.sock. Revisa: journalctl -u php$PHP_V-fpm"

# ---------------------------------------------------------------------------
paso 'Archivo .env y base de datos'
ajustar_permisos
ENV_NUEVO=0
if [ ! -f "$APP/backend/.env" ]; then
  ENV_NUEVO=1
  cp "$APP/deploy/env.produccion" "$APP/backend/.env"
fi

fijar_env() { # llave valor
  if grep -q "^$1=" "$APP/backend/.env"; then
    sed -i "s|^$1=.*|$1=$2|" "$APP/backend/.env"
  else
    echo "$1=$2" >> "$APP/backend/.env"
  fi
}

if [ "$ENV_NUEVO" -eq 1 ]; then
  # Lleva mayúscula, número y símbolo por si MySQL tiene activa una política de contraseñas.
  CLAVE_BD="Rp9_$(openssl rand -hex 24)"
  fijar_env DB_PASSWORD "$CLAVE_BD"
  # El usuario solo tiene permisos sobre su base. 127.0.0.1 es por si el servidor usa skip-name-resolve.
  mysql <<SQL
CREATE DATABASE repapos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'repapos'@'localhost' IDENTIFIED BY '$CLAVE_BD';
CREATE USER 'repapos'@'127.0.0.1' IDENTIFIED BY '$CLAVE_BD';
GRANT ALL PRIVILEGES ON repapos.* TO 'repapos'@'localhost';
GRANT ALL PRIVILEGES ON repapos.* TO 'repapos'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi
ajustar_permisos

instalar_dependencias
ajustar_permisos

# Sin esta llave no se pueden leer los PIN y patrones, por eso nunca se cambia si ya existe.
TABLAS="$(con_usuario_bd mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='repapos'")"
if [ -n "$DATOS" ] && [ "$TABLAS" -gt 0 ]; then
  aviso "La base ya tiene $TABLAS tablas: NO cargo $DATOS para no pisar lo que hay."
  DATOS=''
fi

if [ -n "$DATOS" ]; then
  paso 'Cargando tus datos'
  TMP="$(mktemp -d)"
  chmod 700 "$TMP"
  tar xzf "$DATOS" -C "$TMP"
  [ -s "$TMP/datos.sql" ] && [ -s "$TMP/app_key.txt" ] || fallo 'El paquete de datos está incompleto.'
  fijar_env APP_KEY "$(tr -d '\r\n' < "$TMP/app_key.txt")"
  con_usuario_bd mysql repapos < "$TMP/datos.sql"
  rsync -a --no-owner --no-group "$TMP/storage/public/" "$APP/backend/storage/app/public/"
  rsync -a --no-owner --no-group "$TMP/storage/private/" "$APP/backend/storage/app/private/"
  rm -rf "$TMP"
  # Trae datos de clientes y la llave: no se deja en el servidor.
  shred -u "$DATOS" || rm -f "$DATOS"
fi

if ! grep -q '^APP_KEY=base64:' "$APP/backend/.env"; then
  fijar_env APP_KEY "base64:$(openssl rand -base64 32)"
fi
ajustar_permisos

# ---------------------------------------------------------------------------
paso 'Sitio web (nginx)'
SITIO=/etc/nginx/sites-available/repapos
ENLACE=/etc/nginx/sites-enabled/repapos
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
grep -q 'sites-enabled' /etc/nginx/nginx.conf || fallo 'nginx no carga la carpeta sites-enabled. Agrega: include /etc/nginx/sites-enabled/*;'
SERVIDORES="$DOMINIO"
[ "$ES_IP" -eq 1 ] || SERVIDORES="$DOMINIO www.$DOMINIO"
sed "s|__SERVIDORES__|$SERVIDORES|" "$APP/deploy/nginx.conf.plantilla" > "$SITIO"
ln -sf "$SITIO" "$ENLACE"
# Un error aquí impediría recargar nginx para todas las apps: si falla, se retira.
if ! nginx -t; then
  rm -f "$ENLACE"
  fallo 'La configuración de RepaPOS no pasó nginx -t y la retiré. Tus otros sitios no cambiaron.'
fi
systemctl reload nginx

if command -v ufw >/dev/null && LC_ALL=C ufw status | grep -q 'Status: active'; then
  ufw allow 'Nginx Full' >/dev/null
fi

# ---------------------------------------------------------------------------
ESQUEMA=http
if [ "$ES_IP" -eq 1 ]; then
  aviso 'Con una IP no hay HTTPS: las contraseñas viajan sin cifrar. Úsalo solo para probar.'
else
  paso 'HTTPS (Let'"'"'s Encrypt, solo para este dominio)'
  MIS_IPS=" $(hostname -I) "
  DOMINIOS_CERT=()
  for d in "$DOMINIO" "www.$DOMINIO"; do
    IP_D="$(getent ahostsv4 "$d" | awk 'NR==1{print $1}' || true)"
    if [ -n "$IP_D" ] && [[ "$MIS_IPS" == *" $IP_D "* ]]; then
      DOMINIOS_CERT+=(-d "$d")
    else
      aviso "$d no apunta a este VPS todavía (responde: ${IP_D:-nada})."
    fi
  done
  if [ -f "/etc/letsencrypt/live/$DOMINIO/fullchain.pem" ] && certbot install --nginx --cert-name "$DOMINIO" --redirect --non-interactive; then
    ESQUEMA=https
  elif [ "${#DOMINIOS_CERT[@]}" -gt 0 ] && [ -n "$CORREO" ]; then
    if certbot --nginx "${DOMINIOS_CERT[@]}" --cert-name "$DOMINIO" --expand --redirect --agree-tos --non-interactive -m "$CORREO"; then
      ESQUEMA=https
    else
      aviso 'certbot no pudo sacar el certificado. Revisa el DNS y vuelve a correr este script.'
    fi
  else
    aviso 'Sin HTTPS por ahora: apunta el dominio al VPS, indica --correo y vuelve a correr este script.'
  fi
fi

# ---------------------------------------------------------------------------
paso 'Configuración final'
URL="$ESQUEMA://$DOMINIO"
fijar_env APP_URL "$URL"
fijar_env FRONTEND_URL "$URL"
fijar_env MOBILE_UPLOAD_URL "$URL"
fijar_env CORS_ALLOWED_ORIGINS "$URL"

artisan config:clear
artisan migrate --force
if [ "$(con_usuario_bd mysql -N -e 'SELECT COUNT(*) FROM repapos.roles')" -eq 0 ]; then
  artisan db:seed --class=RolesAndPermissionsSeeder --force
fi
artisan app:preparar-produccion
generar_caches
ajustar_permisos
systemctl reload "php$PHP_V-fpm"

cat > /etc/cron.d/repapos <<CRON
# Programador de Laravel: respaldo de las 23:30 y limpieza de fotos en borrador
* * * * * $USUARIO cd $APP/backend && php$PHP_V artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/repapos

# ---------------------------------------------------------------------------
paso 'Comprobación'
pedir() { # ruta
  if [ "$ESQUEMA" = https ]; then
    curl -s -o /dev/null -w '%{http_code}' --resolve "$DOMINIO:443:127.0.0.1" "https://$DOMINIO$1" || true
  else
    curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMINIO" "http://127.0.0.1$1" || true
  fi
}
CODIGO_API="$(pedir /api/producto)"
CODIGO_WEB="$(pedir /)"
echo "API: $CODIGO_API   Sitio: $CODIGO_WEB"
[ "$CODIGO_API" = 200 ] && [ "$CODIGO_WEB" = 200 ] \
  || fallo "El sitio no responde bien. Revisa $APP/backend/storage/logs y /var/log/nginx/error.log"

printf '\n\033[1;32mListo: %s\033[0m\n' "$URL"
echo "Sitios activos en nginx: $(ls /etc/nginx/sites-enabled | tr '\n' ' ')"
if ! command -v ufw >/dev/null || ! LC_ALL=C ufw status | grep -q 'Status: active'; then
  aviso 'No activé el firewall de este VPS para no bloquear a tus otras apps. Revisa el de hPanel.'
fi
if [ "$(con_usuario_bd mysql -N -e 'SELECT COUNT(*) FROM repapos.users')" -eq 0 ]; then
  aviso 'Todavía no hay usuarios. Crea tu taller y tu usuario de dueño con:'
  echo "  cd $APP/backend && sudo -u $USUARIO php$PHP_V artisan app:crear-taller --superadmin --cortesia"
fi

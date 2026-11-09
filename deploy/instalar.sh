#!/usr/bin/env bash
# Instala RepaPOS en un VPS con Ubuntu 24.04. Se puede volver a correr: no borra datos ni cambia las claves.
#
#   bash deploy/instalar.sh --dominio repapos.tech --correo tu@correo.com --datos /root/repapos-datos-XXXX.tar.gz
#
# --dominio  Dominio ya apuntando al VPS (o la IP, solo para probar: sin HTTPS).
# --correo   Correo para los avisos de Let's Encrypt.
# --datos    Opcional. Paquete con tus datos, hecho con empaquetar.ps1 -ConDatos. Solo se carga si la base está vacía.
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
exigir_paquete
[ -n "$DOMINIO" ] || fallo 'Falta --dominio.'
[ -z "$DATOS" ] || [ -f "$DATOS" ] || fallo "No existe el paquete de datos: $DATOS"
. /etc/os-release
[ "${ID:-}" = ubuntu ] && [ "${VERSION_ID:-}" = '24.04' ] \
  || fallo "Este script es para Ubuntu 24.04 y este servidor tiene ${PRETTY_NAME:-otro sistema}. En hPanel puedes reinstalar el VPS con la plantilla Ubuntu 24.04."

ES_IP=0
[[ "$DOMINIO" =~ ^[0-9.]+$ ]] && ES_IP=1

# ---------------------------------------------------------------------------
paso 'Paquetes del sistema'
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q nginx mysql-server rsync unzip curl openssl ufw fail2ban cron composer \
  certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd
systemctl enable --now mysql nginx php8.3-fpm cron fail2ban

cat > /etc/php/8.3/fpm/conf.d/99-repapos.ini <<'INI'
upload_max_filesize = 12M
post_max_size = 64M
memory_limit = 256M
date.timezone = America/Mexico_City
expose_php = Off
INI

# ---------------------------------------------------------------------------
paso 'Código'
copiar_codigo
ajustar_permisos

# ---------------------------------------------------------------------------
paso 'Archivo .env y base de datos'
ENV_NUEVO=0
if [ ! -f "$APP/backend/.env" ]; then
  ENV_NUEVO=1
  cp "$ORIGEN/deploy/env.produccion" "$APP/backend/.env"
fi

fijar_env() { # llave valor
  if grep -q "^$1=" "$APP/backend/.env"; then
    sed -i "s|^$1=.*|$1=$2|" "$APP/backend/.env"
  else
    echo "$1=$2" >> "$APP/backend/.env"
  fi
}

if [ "$ENV_NUEVO" -eq 1 ]; then
  CLAVE_BD="$(openssl rand -hex 24)"
  fijar_env DB_PASSWORD "$CLAVE_BD"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS repapos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'repapos'@'localhost' IDENTIFIED BY '$CLAVE_BD';
ALTER USER 'repapos'@'localhost' IDENTIFIED BY '$CLAVE_BD';
GRANT ALL PRIVILEGES ON repapos.* TO 'repapos'@'localhost';
FLUSH PRIVILEGES;
SQL
fi
ajustar_permisos

instalar_dependencias
ajustar_permisos

# Sin esta llave no se pueden leer los PIN y patrones, por eso nunca se cambia si ya existe.
TABLAS="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='repapos'")"
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
  mysql repapos < "$TMP/datos.sql"
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
SERVIDORES="$DOMINIO"
[ "$ES_IP" -eq 1 ] || SERVIDORES="$DOMINIO www.$DOMINIO"
sed "s|__SERVIDORES__|$SERVIDORES|" "$ORIGEN/deploy/nginx.conf.plantilla" > /etc/nginx/sites-available/repapos
ln -sf /etc/nginx/sites-available/repapos /etc/nginx/sites-enabled/repapos
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
systemctl restart php8.3-fpm

paso 'Firewall'
PUERTO_SSH="${SSH_CONNECTION:-}"
PUERTO_SSH="${PUERTO_SSH##* }"
ufw allow "${PUERTO_SSH:-22}/tcp" >/dev/null   # el puerto de esta conexión, para no dejarte fuera
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null

# ---------------------------------------------------------------------------
ESQUEMA=http
if [ "$ES_IP" -eq 1 ]; then
  aviso 'Con una IP no hay HTTPS: las contraseñas viajan sin cifrar. Úsalo solo para probar.'
else
  paso 'HTTPS (Let'"'"'s Encrypt)'
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
if [ "$(mysql -N -e 'SELECT COUNT(*) FROM repapos.roles')" -eq 0 ]; then
  artisan db:seed --class=RolesAndPermissionsSeeder --force
fi
artisan app:preparar-produccion
generar_caches
ajustar_permisos
systemctl restart php8.3-fpm

cat > /etc/cron.d/repapos <<'CRON'
# Programador de Laravel: respaldo de las 23:30 y limpieza de fotos en borrador
* * * * * www-data cd /var/www/repapos/backend && php artisan schedule:run >> /dev/null 2>&1
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
if [ "$(mysql -N -e 'SELECT COUNT(*) FROM repapos.users')" -eq 0 ]; then
  aviso 'Todavía no hay usuarios. Crea tu taller y tu usuario de dueño con:'
  echo "  cd $APP/backend && sudo -u www-data php artisan app:crear-taller --superadmin --cortesia"
fi

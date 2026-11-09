# Funciones que comparten instalar.sh y actualizar.sh (se carga con "source").
# RepaPOS convive con otras apps en el mismo VPS: tiene su propio usuario, su propio
# proceso de PHP y su propia base. Nada de aquí debe cambiar algo que sea de otra app.

APP=/var/www/repapos
USUARIO=repapos
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

paso() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
aviso() { printf '\033[1;33m%s\033[0m\n' "$1"; }
fallo() { printf '\033[1;31m%s\033[0m\n' "$1" >&2; exit 1; }

# Versión de PHP con la que ya quedó instalado RepaPOS (vacío si todavía no).
detectar_php() {
  local f
  for f in /etc/php/*/fpm/pool.d/repapos.conf; do
    [ -f "$f" ] && { echo "$f" | cut -d/ -f4; return 0; }
  done
  return 0
}
PHP_V="$(detectar_php)"

artisan() { (cd "$APP/backend" && sudo -u "$USUARIO" "php$PHP_V" artisan "$@"); }

# Corre mysql o mysqldump con el usuario de RepaPOS: la clave de root solo hace falta al crear la base.
con_usuario_bd() { # programa argumentos...
  local programa="$1" cnf estado=0
  shift
  cnf="$(mktemp)"
  chmod 600 "$cnf"
  {
    echo '[client]'
    echo "user=$(grep '^DB_USERNAME=' "$APP/backend/.env" | cut -d= -f2-)"
    echo "password=\"$(grep '^DB_PASSWORD=' "$APP/backend/.env" | cut -d= -f2-)\""
    echo "host=$(grep '^DB_HOST=' "$APP/backend/.env" | cut -d= -f2-)"
  } > "$cnf"
  "$programa" --defaults-extra-file="$cnf" "$@" || estado=$?
  rm -f "$cnf"
  return "$estado"
}

exigir_root() {
  [ "$(id -u)" -eq 0 ] || fallo 'Corre este script como root.'
}

# El clon de Git es la instalación: se usa ahí mismo, sin copiarlo a otro lado.
exigir_codigo() {
  [ "$RAIZ" = "$APP" ] || fallo "El proyecto debe estar en $APP y este script está en $RAIZ. Muévelo con: mv $RAIZ $APP"
  [ -f "$APP/backend/artisan" ] && [ -f "$APP/frontend/dist/frontend/browser/index.html" ] \
    || fallo "No encuentro el código o Angular compilado en $APP: revisa el clon y corre git pull."
}

# El código es de root. El usuario repapos solo escribe en storage y bootstrap/cache,
# y las otras apps no pueden leer su .env ni sus archivos privados.
ajustar_permisos() {
  mkdir -p "$APP"/backend/storage/app/{public,private} \
           "$APP"/backend/storage/framework/{cache/data,sessions,views} \
           "$APP"/backend/storage/logs \
           "$APP"/backend/bootstrap/cache
  chown -R "$USUARIO:$USUARIO" "$APP/backend/storage" "$APP/backend/bootstrap/cache"
  chmod -R u+rwX,go-rwx "$APP/backend/storage"
  # nginx entra por el grupo repapos, y solo puede leer las fotos públicas
  chmod g+x "$APP/backend/storage" "$APP/backend/storage/app"
  chmod -R g+rX "$APP/backend/storage/app/public"
  [ ! -f "$APP/backend/.env" ] || { chown "root:$USUARIO" "$APP/backend/.env"; chmod 640 "$APP/backend/.env"; }
}

instalar_dependencias() {
  (cd "$APP/backend" && COMPOSER_ALLOW_SUPERUSER=1 "php$PHP_V" "$(command -v composer)" install \
    --no-dev --optimize-autoloader --no-interaction --no-progress)
}

generar_caches() {
  artisan config:cache
  artisan route:cache
  artisan view:cache
}

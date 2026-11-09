# Funciones que comparten instalar.sh y actualizar.sh (se carga con "source").

APP=/var/www/repapos
ORIGEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

paso() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
aviso() { printf '\033[1;33m%s\033[0m\n' "$1"; }
fallo() { printf '\033[1;31m%s\033[0m\n' "$1" >&2; exit 1; }

artisan() { (cd "$APP/backend" && sudo -u www-data php artisan "$@"); }

exigir_root() {
  [ "$(id -u)" -eq 0 ] || fallo 'Corre este script como root.'
}

exigir_paquete() {
  [ -f "$ORIGEN/backend/artisan" ] && [ -f "$ORIGEN/frontend/dist/frontend/browser/index.html" ] \
    || fallo "No encuentro el código en $ORIGEN: descomprime el paquete completo y corre el script desde ahí."
}

# Copia el código. Nunca toca .env, storage ni vendor, que son propios del servidor.
copiar_codigo() {
  mkdir -p "$APP/backend" "$APP/frontend"
  local modos='--chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r'
  rsync -a --delete --no-owner --no-group "$modos" \
    --exclude=/.env --exclude=/storage/ --exclude=/vendor/ --exclude=/bootstrap/cache/ \
    "$ORIGEN/backend/" "$APP/backend/"
  rsync -a --delete --no-owner --no-group "$modos" "$ORIGEN/frontend/dist/frontend/browser/" "$APP/frontend/"
  chown root:root "$APP" "$APP/backend" "$APP/frontend"
}

# El código es de root; www-data solo escribe en storage y bootstrap/cache.
ajustar_permisos() {
  mkdir -p "$APP"/backend/storage/app/{public,private} \
           "$APP"/backend/storage/framework/{cache/data,sessions,views} \
           "$APP"/backend/storage/logs \
           "$APP"/backend/bootstrap/cache
  chown -R www-data:www-data "$APP/backend/storage" "$APP/backend/bootstrap/cache"
  chmod -R u+rwX,g+rX,o-rwx "$APP/backend/storage"
  # nginx (www-data) entrega las fotos desde storage/app/public
  [ ! -f "$APP/backend/.env" ] || { chown root:www-data "$APP/backend/.env"; chmod 640 "$APP/backend/.env"; }
}

instalar_dependencias() {
  (cd "$APP/backend" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
}

generar_caches() {
  artisan config:cache
  artisan route:cache
  artisan view:cache
}

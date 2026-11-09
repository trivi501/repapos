#!/usr/bin/env bash
# Baja de GitHub la versión nueva y la aplica. Respalda la base antes de tocar nada.
#
#   bash /var/www/repapos/deploy/actualizar.sh
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

exigir_root
exigir_codigo
[ -f "$APP/backend/.env" ] && [ -n "$PHP_V" ] || fallo "RepaPOS no está instalado: corre primero instalar.sh."
[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ] \
  || fallo "Hay archivos modificados en $APP y git pull los pisaría. Revísalos con: git -C $APP status"

# Aquí se piden tu usuario y token, antes de poner el sistema en mantenimiento.
paso 'Revisando GitHub'
git -C "$APP" fetch origin

paso 'Respaldo de la base de datos'
mkdir -p /var/backups/repapos
chmod 700 /var/backups/repapos
RESPALDO="/var/backups/repapos/antes-$(date +%Y%m%d-%H%M%S).sql.gz"
con_usuario_bd mysqldump --single-transaction --no-tablespaces repapos | gzip > "$RESPALDO"
chmod 600 "$RESPALDO"
echo "$RESPALDO"
# se conservan los últimos 10
ls -1t /var/backups/repapos/antes-*.sql.gz | tail -n +11 | xargs -r rm -f

paso 'Actualizando'
artisan down --retry=30 || true
trap 'aviso "Algo falló y el sitio sigue en mantenimiento. Corrige y vuelve a correr este script, o restaura $RESPALDO y corre: cd $APP/backend && sudo -u $USUARIO php$PHP_V artisan up"' ERR

git -C "$APP" merge --ff-only '@{u}'
instalar_dependencias
ajustar_permisos
artisan config:clear
artisan migrate --force
generar_caches
ajustar_permisos
systemctl reload "php$PHP_V-fpm"   # recarga sin cortar a las otras apps
artisan up

trap - ERR
printf '\n\033[1;32mActualizado a %s.\033[0m\n' "$(git -C "$APP" log -1 --format='%h %s')"

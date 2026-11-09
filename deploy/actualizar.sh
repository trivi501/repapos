#!/usr/bin/env bash
# Sube una versión nueva sobre una instalación que ya funciona. Respalda la base antes de tocar nada.
#
#   bash deploy/actualizar.sh
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

exigir_root
exigir_paquete
[ -f "$APP/backend/.env" ] || fallo "No hay instalación en $APP: corre primero instalar.sh."

paso 'Respaldo de la base de datos'
mkdir -p /var/backups/repapos
chmod 700 /var/backups/repapos
RESPALDO="/var/backups/repapos/antes-$(date +%Y%m%d-%H%M%S).sql.gz"
mysqldump --single-transaction --no-tablespaces repapos | gzip > "$RESPALDO"
chmod 600 "$RESPALDO"
echo "$RESPALDO"
# se conservan los últimos 10
ls -1t /var/backups/repapos/antes-*.sql.gz | tail -n +11 | xargs -r rm -f

paso 'Actualizando'
artisan down --retry=30 || true
trap 'aviso "Algo falló y el sitio sigue en mantenimiento. Corrige y vuelve a correr este script, o restaura $RESPALDO y corre: cd $APP/backend && sudo -u www-data php artisan up"' ERR

copiar_codigo
instalar_dependencias
ajustar_permisos
artisan config:clear
artisan migrate --force
generar_caches
ajustar_permisos
systemctl restart php8.3-fpm
artisan up

trap - ERR
printf '\n\033[1;32mActualizado.\033[0m\n'

#!/usr/bin/env bash
# Instala Evolution API (WhatsApp por código QR) en este VPS y la conecta con RepaPOS.
# Usa Docker, escucha solo en 127.0.0.1 y no toca nginx ni las otras apps.
#
#   bash /var/www/repapos/deploy/instalar-evolution.sh
#
# Volver a correrlo es seguro: conserva la clave y los datos que ya existan.
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

exigir_root
[ -f "$APP/backend/.env" ] && [ -n "$PHP_V" ] || fallo "RepaPOS no está instalado: corre primero instalar.sh."

DESTINO=/opt/repapos-evolution
PUERTO="${EVOLUTION_PORT:-8085}"

# Docker y "docker compose" del repositorio oficial de Docker (Debian no trae docker-compose-v2)
repo_docker() {
  . /etc/os-release
  case "$ID" in
    debian|ubuntu) ;;
    *) fallo "Instala Docker a mano para $PRETTY_NAME (https://docs.docker.com/engine/install/) y vuelve a correr este script." ;;
  esac
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$ID $VERSION_CODENAME stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
}

paso 'Docker'
if ! command -v docker >/dev/null 2>&1; then
  aviso 'Docker no está instalado. Instalándolo desde el repositorio oficial de Docker…'
  repo_docker
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
elif ! docker compose version >/dev/null 2>&1; then
  aviso 'Docker ya está, pero falta "docker compose". Instalándolo desde el repositorio oficial de Docker…'
  repo_docker
  apt-get install -y -qq docker-compose-plugin
fi
docker compose version >/dev/null 2>&1 || fallo 'No se pudo instalar "docker compose". Sigue https://docs.docker.com/engine/install/debian/ y vuelve a correr este script.'

if ss -ltn "sport = :$PUERTO" | grep -q LISTEN && ! docker ps --format '{{.Names}}' | grep -q repapos-evolution; then
  fallo "El puerto $PUERTO ya lo usa otra app. Corre de nuevo con otro: EVOLUTION_PORT=8086 bash $0"
fi

paso 'Configuración de Evolution API'
mkdir -p "$DESTINO"
cp "$APP/deploy/evolution/docker-compose.yml" "$DESTINO/docker-compose.yml"
if [ ! -f "$DESTINO/.env" ]; then
  {
    echo "EVOLUTION_API_KEY=$(openssl rand -hex 32)"
    echo "EVOLUTION_DB_PASSWORD=$(openssl rand -hex 24)"
    echo "EVOLUTION_PORT=$PUERTO"
    echo '# EVOLUTION_IMAGE=atendai/evolution-api:v2.1.1'
    echo '# si el QR no aparece, WhatsApp cambió de versión (ver deploy/EVOLUTION-API.md):'
    echo '# CONFIG_SESSION_PHONE_VERSION='
  } > "$DESTINO/.env"
fi
chmod 600 "$DESTINO/.env"
CLAVE="$(grep '^EVOLUTION_API_KEY=' "$DESTINO/.env" | cut -d= -f2-)"
PUERTO="$(grep '^EVOLUTION_PORT=' "$DESTINO/.env" | cut -d= -f2-)"

paso 'Levantando Evolution API'
(cd "$DESTINO" && docker compose pull -q && docker compose up -d)

paso 'Esperando a que responda'
for _ in $(seq 1 30); do
  if curl -fsS -H "apikey: $CLAVE" "http://127.0.0.1:$PUERTO/instance/fetchInstances" >/dev/null 2>&1; then
    LISTA=1; break
  fi
  sleep 2
done
[ "${LISTA:-}" = 1 ] || fallo "Evolution API no respondió. Revisa: cd $DESTINO && docker compose logs --tail 50 evolution"

paso 'Conectando con RepaPOS'
ENV="$APP/backend/.env"
poner_env() { # clave valor
  if grep -q "^$1=" "$ENV"; then sed -i "s|^$1=.*|$1=$2|" "$ENV"; else echo "$1=$2" >> "$ENV"; fi
}
poner_env EVOLUTION_URL "http://127.0.0.1:$PUERTO"
poner_env EVOLUTION_API_KEY "$CLAVE"
ajustar_permisos
artisan config:cache

printf '\n\033[1;32mEvolution API lista.\033[0m Ahora en RepaPOS: Configuración → WhatsApp → Evolution API → "Vincular con código QR".\n'
printf 'Datos y clave en %s (no los compartas).\n' "$DESTINO/.env"

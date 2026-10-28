#!/usr/bin/env bash
# Instala/actualiza los scripts de deploy y los compose de confianza en /opt/deploy.
# Corre como root. Se ejecuta desde checkouts de main (no desde ramas feature):
#
#   sudo ops/vps/install.sh <checkout-api> <checkout-front>
#
# Todo queda de root y sin escritura para `deploy`: si deploy pudiera editar
# gate.sh o los compose, las restricciones de las llaves no servirían de nada.
set -euo pipefail

API="${1:?checkout de edutimetable-api}"; FRONT="${2:?checkout de edutimetable-front}"
[ "$(id -u)" -eq 0 ] || { echo "correr como root" >&2; exit 1; }

install -d -m 755 -o root -g root /opt/deploy /opt/deploy/compose/api /opt/deploy/compose/front
for s in deploy.sh gate.sh backup-db.sh offsite-backup.sh; do
    install -m 755 -o root -g root "$API/ops/vps/$s" "/opt/deploy/$s"
done
install -m 644 -o root -g root "$API/docker-compose.prod.yml" /opt/deploy/compose/api/
install -m 644 -o root -g root "$FRONT/docker-compose.prod.yml" "$FRONT/docker-compose.staging.yml" /opt/deploy/compose/front/

install -d -m 755 -o deploy -g deploy /srv/backups /srv/backups/edutimetable /var/log/deploy
rev() { git -C "$1" rev-parse --short HEAD 2>/dev/null || echo "sin-git"; }
echo "instalado desde api@$(rev "$API") front@$(rev "$FRONT")"
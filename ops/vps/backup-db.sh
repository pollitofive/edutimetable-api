#!/usr/bin/env bash
# Dump de la DB de un entorno a /srv/backups/edutimetable/<env>/.
#
#   backup-db.sh <staging|prod> [etiqueta]
#
# Lo usan deploy.sh (antes de migrar en prod) y offsite-backup.sh (cron diario).
# Los dumps locales se borran a los 14 días; la copia fuera del server la hace
# offsite-backup.sh.
set -euo pipefail

ENVIRONMENT="${1:-}"; TAG="${2:-manual}"
[[ "$ENVIRONMENT" =~ ^(staging|prod)$ ]] || { echo "uso: backup-db.sh <staging|prod> [etiqueta]" >&2; exit 1; }
[[ "$TAG" =~ ^[A-Za-z0-9._-]{1,60}$ ]] || { echo "etiqueta inválida" >&2; exit 1; }

# Con sudo -u deploy el cwd puede ser un home ajeno (/root en el cron) y find falla
cd /
DIR="/srv/backups/edutimetable/$ENVIRONMENT"
mkdir -p "$DIR"
OUT="$DIR/$(date +%Y%m%d-%H%M%S)-$TAG.sql.gz"
trap 'rm -f "$OUT.tmp"' EXIT

docker compose --project-directory "/srv/edutimetable/$ENVIRONMENT/api" \
    -f /opt/deploy/compose/api/docker-compose.prod.yml exec -T db sh -c \
    'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
    2> >(grep -v "Using a password on the command line" >&2) \
    | gzip -9 > "$OUT.tmp"

# Un dump vacío o cortado no cuenta como backup
gzip -t "$OUT.tmp"
[ "$(gzip -dc "$OUT.tmp" | tail -1 | grep -c 'Dump completed')" -eq 1 ] || { rm -f "$OUT.tmp"; echo "dump incompleto" >&2; exit 1; }
mv "$OUT.tmp" "$OUT"

find "$DIR" -name '*.sql.gz' -mtime +14 -delete
echo "backup: $OUT ($(du -h "$OUT" | cut -f1))"
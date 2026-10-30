#!/usr/bin/env bash
# Backup diario de prod fuera del server (cron de root, ver README).
# Dump nuevo → Google Drive cifrado (remote rclone "gcrypt").
# Retención en Drive: 7 diarios + 4 semanales (copia de los domingos).
# También sube los .env de prod (APP_KEY y credenciales): sin ellos el dump solo no
# alcanza para reconstruir el server.
set -euo pipefail
LOG=/var/log/deploy/offsite-backup.log
exec >> "$LOG" 2>&1

# Monitoreo con healthchecks.io: aviso por mail si el backup falla o no corre.
# La URL de ping no está en el repo: va en /etc/deploy/healthchecks-url (root, 600).
# Sin ese archivo el backup corre igual, pero sin aviso.
HC_URL="$(cat /etc/deploy/healthchecks-url 2>/dev/null || true)"
hc() { [ -z "$HC_URL" ] || curl -fsS -m 10 --retry 3 -o /dev/null "$HC_URL$1" "${@:2}" || true; }
# El código de salida va en la URL (0 = OK, otro = falla), con el final del log de cuerpo.
trap 'hc "/$?" --data-raw "$(tail -n 20 "$LOG")"' EXIT
hc /start

DEST=gcrypt:edutimetable/prod
OUT="$(sudo -u deploy /opt/deploy/backup-db.sh prod daily | sed -n 's/^backup: \(.*\) (.*/\1/p')"
[ -f "$OUT" ] || { echo "no se generó el dump" >&2; exit 1; }

rclone copy "$OUT" "$DEST/daily/"
if [ "$(date +%u)" = 7 ]; then
    rclone copy "$OUT" "$DEST/weekly/"
fi

for c in api front; do
    rclone copyto "/srv/edutimetable/prod/$c/.env" "$DEST/env/$c.env"
done

rclone mkdir "$DEST/weekly"
rclone delete --min-age 8d "$DEST/daily/"
rclone delete --min-age 29d "$DEST/weekly/"
echo "$(date -Is) offsite OK: $(basename "$OUT")"
#!/usr/bin/env bash
# Backup diario de prod fuera del server (cron de root, ver README).
# Dump nuevo → Google Drive cifrado (remote rclone "gcrypt").
# Retención en Drive: 7 diarios + 4 semanales (copia de los domingos).
set -euo pipefail

DEST=gcrypt:edutimetable/prod
OUT="$(sudo -u deploy /opt/deploy/backup-db.sh prod daily | sed -n 's/^backup: \(.*\) (.*/\1/p')"
[ -f "$OUT" ] || { echo "no se generó el dump" >&2; exit 1; }

rclone copy "$OUT" "$DEST/daily/"
if [ "$(date +%u)" = 7 ]; then
    rclone copy "$OUT" "$DEST/weekly/"
fi

rclone delete --min-age 8d "$DEST/daily/"
rclone delete --min-age 29d "$DEST/weekly/"
echo "$(date -Is) offsite OK: $(basename "$OUT")"
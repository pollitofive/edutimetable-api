#!/bin/sh
# Arma los caches con el .env real del entorno en cada arranque del contenedor,
# así un restart no pierde config:cache. Las migraciones NO van acá: las corre
# el script de deploy, una sola vez y después del backup.
set -e

php artisan optimize
php artisan lighthouse:cache

exec "$@"

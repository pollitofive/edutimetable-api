#!/usr/bin/env bash
# Deploy de un componente de edutimetable en el VPS.
#
#   deploy.sh <api|front> <staging|prod> <ref>
#
# <ref> es una rama del remoto o un SHA. Corre como el usuario `deploy`.
# Pasos: lock → fetch/checkout → (api+prod: backup DB) → build/up → (api: migrate)
#        → healthcheck interno → si falla, vuelve al commit anterior.
# Las migraciones NO se revierten en el rollback: los cambios de schema tienen que
# ser compatibles hacia atrás (y en prod está el backup previo).
set -euo pipefail

COMPONENT="${1:-}"; ENVIRONMENT="${2:-}"; REF="${3:-}"
BASE=/srv/edutimetable
OPS="$(dirname "$(readlink -f "$0")")"

die() { echo "ERROR: $*" >&2; exit 1; }

[[ "$COMPONENT" =~ ^(api|front)$ ]] || die "componente inválido: '$COMPONENT' (api|front)"
[[ "$ENVIRONMENT" =~ ^(staging|prod)$ ]] || die "entorno inválido: '$ENVIRONMENT' (staging|prod)"
[[ "$REF" =~ ^[A-Za-z0-9._/-]{1,100}$ && "$REF" != *..* ]] || die "ref inválida: '$REF'"

DIR="$BASE/$ENVIRONMENT/$COMPONENT"
[ -d "$DIR/.git" ] || die "$DIR no está instalado"
LOG="/var/log/deploy/edutimetable-$ENVIRONMENT-$COMPONENT.log"

exec 9>"/tmp/deploy-edutimetable-$ENVIRONMENT-$COMPONENT.lock"
flock -n 9 || die "ya hay un deploy en curso para $ENVIRONMENT/$COMPONENT"

exec > >(tee -a "$LOG") 2>&1
echo "===== $(date -Is) deploy $COMPONENT $ENVIRONMENT $REF"

cd "$DIR"
# Los compose salen de /opt/deploy/compose (de root, instalados desde main con
# install.sh), NO de la rama que se deploya: si no, cualquier rama pusheada podría
# montar / del host o docker.sock y tomar el VPS entero (prod incluido). La rama solo
# aporta código y Dockerfile, que buildea aislado del host.
COMPOSE=(docker compose --project-directory "$DIR" -f "$OPS/compose/$COMPONENT/docker-compose.prod.yml")
if [ "$COMPONENT" = front ] && [ "$ENVIRONMENT" = staging ]; then
    COMPOSE+=(-f "$OPS/compose/$COMPONENT/docker-compose.staging.yml")
fi

PREV="$(git rev-parse HEAD)"
git fetch --prune --quiet origin
if git rev-parse --verify --quiet "origin/$REF^{commit}" >/dev/null; then
    TARGET="$(git rev-parse "origin/$REF")"
elif git rev-parse --verify --quiet "$REF^{commit}" >/dev/null; then
    TARGET="$(git rev-parse "$REF")"
else
    die "no encuentro la ref '$REF' en origin"
fi
echo "commit anterior: $PREV"
echo "commit nuevo:    $TARGET"

healthcheck() {
    local path=/
    [ "$COMPONENT" = api ] && path=/up
    for _ in $(seq 1 30); do
        if "${COMPOSE[@]}" exec -T web wget -q -O /dev/null "http://127.0.0.1$path" 2>/dev/null; then
            return 0
        fi
        sleep 2
    done
    return 1
}

release() {
    git checkout --quiet --force --detach "$1"
    "${COMPOSE[@]}" up -d --build --remove-orphans
    if [ "$COMPONENT" = api ]; then
        "${COMPOSE[@]}" exec -T app php artisan migrate --force
        # El entrypoint arma los caches al arrancar; reiniciar app los rehace con el código nuevo
        # aunque la imagen no haya cambiado (por ejemplo, si solo cambió el .env).
        "${COMPOSE[@]}" restart app
    fi
}

if [ "$COMPONENT" = api ] && [ "$ENVIRONMENT" = prod ]; then
    # Sin volumen de la DB es el primer deploy y no hay nada que respaldar. Si el volumen
    # existe y la DB no responde, backup-db.sh falla y el deploy se corta: nunca sin backup.
    PROJECT="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' .env)"
    if docker volume inspect "${PROJECT}_db_data" >/dev/null 2>&1; then
        "$OPS/backup-db.sh" prod "pre-deploy-${TARGET:0:7}"
    else
        echo "sin volumen ${PROJECT}_db_data: primer deploy, no hay backup que hacer"
    fi
fi

if release "$TARGET" && healthcheck; then
    docker image prune -f >/dev/null
    echo "OK: $COMPONENT $ENVIRONMENT en ${TARGET:0:7}"
    exit 0
fi

echo "FALLÓ el deploy de ${TARGET:0:7}; vuelvo a ${PREV:0:7}"
if [ "$TARGET" != "$PREV" ]; then
    git checkout --quiet --force --detach "$PREV"
    "${COMPOSE[@]}" up -d --build --remove-orphans || true
    healthcheck && echo "rollback OK" || echo "ROLLBACK TAMBIÉN FALLÓ: revisar a mano"
fi
exit 1
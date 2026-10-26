#!/usr/bin/env bash
# Comando forzado de las llaves SSH del usuario `deploy` (authorized_keys):
#
#   command="/opt/deploy/gate.sh <rol>",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 ...
#
# Roles:
#   staging → deploy a staging, logs y status (llave de Claude y de CI para staging)
#   prod    → lo mismo + deploy a prod (llave de CI guardada como secret del
#             environment "production", que GitHub solo entrega tras la aprobación)
#
# Órdenes aceptadas (en $SSH_ORIGINAL_COMMAND):
#   deploy <api|front> <staging|prod> <ref>
#   logs   <api|front> <staging|prod> [líneas]
#   deploylog <api|front> <staging|prod> [líneas]
#   status
set -euo pipefail

ROLE="${1:-}"
OPS="$(dirname "$(readlink -f "$0")")"
read -r -a ARGS <<< "${SSH_ORIGINAL_COMMAND:-}"
CMD="${ARGS[0]:-}"

die() { echo "gate: $*" >&2; exit 1; }
logger -t deploy-gate "role=$ROLE cmd=${SSH_ORIGINAL_COMMAND:-<vacío>}"

valid_target() {
    [[ "${1:-}" =~ ^(api|front)$ ]] || die "componente inválido"
    [[ "${2:-}" =~ ^(staging|prod)$ ]] || die "entorno inválido"
}
lines() { [[ "${1:-}" =~ ^[0-9]{1,3}$ ]] && echo "$1" || echo 100; }

case "$CMD" in
    deploy)
        [ "${#ARGS[@]}" -eq 4 ] || die "uso: deploy <api|front> <staging|prod> <ref>"
        valid_target "${ARGS[1]}" "${ARGS[2]}"
        if [ "${ARGS[2]}" = prod ] && [ "$ROLE" != prod ]; then
            die "esta llave no puede deployar a prod"
        fi
        exec "$OPS/deploy.sh" "${ARGS[1]}" "${ARGS[2]}" "${ARGS[3]}"
        ;;
    logs)
        valid_target "${ARGS[1]:-}" "${ARGS[2]:-}"
        exec docker compose --project-directory "/srv/edutimetable/${ARGS[2]}/${ARGS[1]}" \
            -f "$OPS/compose/${ARGS[1]}/docker-compose.prod.yml" \
            logs --no-color --tail "$(lines "${ARGS[3]:-}")"
        ;;
    deploylog)
        valid_target "${ARGS[1]:-}" "${ARGS[2]:-}"
        exec tail -n "$(lines "${ARGS[3]:-}")" "/var/log/deploy/edutimetable-${ARGS[2]}-${ARGS[1]}.log"
        ;;
    status)
        docker ps --filter "name=edutimetable-" --format "table {{.Names}}\t{{.Status}}"
        for d in /srv/edutimetable/*/*/; do
            [ -d "$d/.git" ] && echo "$d $(git -C "$d" log -1 --format='%h %cs %s')"
        done
        ;;
    *)
        die "orden no permitida: '${CMD}'"
        ;;
esac

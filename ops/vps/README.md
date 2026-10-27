# Deploy de edutimetable en el VPS

Flujo: push a `feature/*` o `fix/*` → GitHub Actions deploya a **staging**. Merge a `main` → job de prod frenado hasta la aprobación manual (environment `production`) → **prod**.

## Piezas en el server

| Qué                                                                   | Dónde                                           | Dueño                            |
|-----------------------------------------------------------------------|-------------------------------------------------|----------------------------------|
| Scripts (`deploy.sh`, `gate.sh`, `backup-db.sh`, `offsite-backup.sh`) | `/opt/deploy/`                                  | root, solo lectura para `deploy` |
| Compose de confianza                                                  | `/opt/deploy/compose/{api,front}/`              | root                             |
| Checkouts + `.env` de cada entorno                                    | `/srv/edutimetable/{staging,prod}/{api,front}/` | deploy                           |
| Dumps locales (14 días)                                               | `/srv/backups/edutimetable/<env>/`              | deploy                           |
| Logs de deploy                                                        | `/var/log/deploy/`                              | deploy                           |

Traefik (`/docker/traefik`) corre con `network_mode: host`, entrypoint `websecure` y certresolver `letsencrypt`.

**Los compose salen de `/opt/deploy/compose`, no de la rama deployada.** Cambiar un `docker-compose.prod.yml` del repo no tiene efecto hasta mergearlo a `main` y correr `install.sh` (a mano, con sudo).

## Acceso: usuario `deploy` y llaves con comando forzado

`/home/deploy/.ssh/authorized_keys`: cada llave solo puede ejecutar `gate.sh` con su rol.

```
command="/opt/deploy/gate.sh staging",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 ... claude
command="/opt/deploy/gate.sh staging",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 ... gh-actions-staging
command="/opt/deploy/gate.sh prod",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 ... gh-actions-prod
```

Órdenes: `deploy <api|front> <staging|prod> <ref>`, `logs <api|front> <env> [n]`, `deploylog <api|front> <env> [n]`, `status`. Ejemplo: `ssh deploy@vps status`.

Ojo: `deploy` está en el grupo docker, que equivale a root en la práctica. Lo que limita a las llaves es el comando forzado de sshd, no los permisos de Unix.

## GitHub Actions

`.github/workflows/deploy.yml` (igual en los dos repos, cambia `COMPONENT`) corre `ssh deploy@vps deploy <componente> <env> <sha>`.

En cada repo, Settings → Environments:

| Environment  | Secret `DEPLOY_SSH_KEY`    | Protección                                           |
|--------------|----------------------------|------------------------------------------------------|
| `staging`    | llave `gh-actions-staging` | ninguna                                              |
| `production` | llave `gh-actions-prod`    | required reviewer + deployment branches: solo `main` |

La regla de branches es la que impide que una rama cambie el workflow para pedir el environment `production`: sin ella, el único freno sería la aprobación.

La host key del VPS está fija en el workflow (`VPS_HOST_KEY`). Si se reinstala el server, actualizarla en los dos repos.

## Instalar un entorno desde cero

```bash
# 1. Scripts y compose (como root, desde checkouts de main)
sudo ops/vps/install.sh /ruta/edutimetable-api /ruta/edutimetable-front

# 2. Checkouts (como deploy; deploy keys de solo lectura con alias en ~deploy/.ssh/config)
git clone github-edutimetable-api:pollitofive/edutimetable-api.git /srv/edutimetable/<env>/api
git clone github-edutimetable-front:pollitofive/edutimetable-front.git /srv/edutimetable/<env>/front

# 3. .env de cada uno a partir de .env.production.example (chmod 600)

# 4. Primer deploy
/opt/deploy/deploy.sh api <env> main
/opt/deploy/deploy.sh front <env> main
```

## Backups

- `deploy.sh` hace un dump antes de migrar en prod.
- Cron de root, diario: `offsite-backup.sh` hace un dump de prod, lo sube a Google Drive cifrado (rclone `gcrypt`) y mantiene 7 diarios y 4 semanales.
- La clave de `gcrypt` está en el gestor de contraseñas. Sin esa clave los backups no se pueden leer.

Restore:

```bash
rclone copy gcrypt:edutimetable/prod/daily/<archivo>.sql.gz /tmp/
gunzip -c /tmp/<archivo>.sql.gz | docker compose --project-directory /srv/edutimetable/prod/api \
  -f /opt/deploy/compose/api/docker-compose.prod.yml exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```
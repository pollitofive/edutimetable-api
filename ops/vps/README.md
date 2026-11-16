# Deploy de edutimetable en el VPS

Flujo: push a `feature/*`, `fix/*`, `hotfix/*` o merge a `main` → GitHub Actions deploya a **staging**. Push de un tag `vX.Y.Z` (lo crea `/release`, con la misma versión en api y front) → job de prod frenado hasta la aprobación manual (environment `production`) → **prod**.

Mergear a `main` no saca nada a prod: los cambios se acumulan en `main` hasta el próximo release.

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
| `production` | llave `gh-actions-prod`    | required reviewer + deployment tags: solo `v*`       |

La regla de tags es la que impide que una rama cambie el workflow para pedir el environment `production`: sin ella, el único freno sería la aprobación. Ojo: un tag `v*` puede apuntar a cualquier commit, incluso uno de una rama con el workflow modificado. Lo que frena eso es que solo el dueño del repo puede pushear y que prod siempre pide aprobación.

Un push a una rama que solo toca `ops/**` no dispara deploy (`paths-ignore`). Los tags no pasan por ese filtro: siempre deployan. Si cambian scripts o compose, correr `install.sh` **antes** de pushear `main` (staging) y, en todo caso, antes de tagear: si no, un deploy que arranque en ese momento corre con el script viejo.

### Hotfix

Si hay que arreglar prod y `main` tiene cambios sin liberar, no se tagea `main`:

1. `git switch -c hotfix/<n>-<slug> vX.Y.Z` desde el último tag (en el repo afectado; si son los dos, en ambos).
2. Fix, commit y push: la rama deploya a staging para probarla.
3. Tag `vX.Y.(Z+1)` sobre la rama del hotfix (en los dos repos: la versión es conjunta; el repo sin cambios se tagea sobre su último tag) → prod con aprobación.
4. Merge de la rama del hotfix a `main` para no perder el fix.

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
- Cron de root, diario (`/etc/cron.d/edutimetable-backup`, 03:30 UTC): `offsite-backup.sh` hace un dump de prod, lo sube a Google Drive cifrado (rclone `gcrypt`) junto con los `.env` de prod, y mantiene 7 diarios y 4 semanales. Log: `/var/log/deploy/offsite-backup.log`.
- La clave de `gcrypt` está en el gestor de contraseñas. Sin esa clave los backups no se pueden leer.
- Monitoreo con healthchecks.io: `offsite-backup.sh` hace ping al empezar y al terminar, con el código de salida y el final del log. Si falla o no corre en el día, llega un mail. La URL de ping está en `/etc/deploy/healthchecks-url` (root, 600) y no en el repo.

Restore:

```bash
rclone copy gcrypt:edutimetable/prod/daily/<archivo>.sql.gz /tmp/
gunzip -c /tmp/<archivo>.sql.gz | docker compose --project-directory /srv/edutimetable/prod/api \
  -f /opt/deploy/compose/api/docker-compose.prod.yml exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```
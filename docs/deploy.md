# SpatialShelf — deploy runbook

How the live server is built, how to redeploy it, where the logs are,
and how to verify it. The deploy kit lives in `deploy/`:

| File | Used |
| --- | --- |
| `deploy/server-setup.sh` | Once, as root: user, firewall, swap, nginx, MySQL 8, PHP 8.4 (Ondrej PPA — composer.lock needs ≥ 8.4), Python 3.11 (deadsnakes), Node 20, ffmpeg, supervisor, certbot, DB + user |
| `deploy/nginx-spatialshelf.conf` | Nginx site template (`DOMAIN` replaced at install) |
| `deploy/supervisor-queue.conf` | Laravel `queue:work` on `scans,whatsapp,default` |
| `deploy/supervisor-worker.conf` | Python worker (uvicorn on 127.0.0.1:8080) |
| `deploy/deploy.sh` | Every deploy: composer, npm build, migrate, worker venv, restarts |

## Server layout

- Code: `/var/www/spatialshelf` (this repo, cloned over https), owned by `shelf`.
- Laravel: `/var/www/spatialshelf/app`, served from `app/public` by nginx → php8.3-fpm.
- Worker venv: `/var/www/spatialshelf/worker/.venv` (torch CPU, DINOv2).
- Shared files: `/var/www/spatialshelf/storage-shared` (`SHARED_STORAGE_PATH`).
- DB: MySQL 8, database `spatialshelf`, user `spatialshelf'@'localhost`.
- Services: `spatialshelf-queue`, `spatialshelf-worker` under supervisor.

## First deploy (order matters)

1. Droplet (Ubuntu 24.04, 2 vCPU / 4 GB) with your SSH key; DNS points at it.
2. `scp deploy/server-setup.sh root@HOST:/root/` then
   `ssh root@HOST 'bash /root/server-setup.sh'`. Copy the MySQL
   password it prints once.
3. As `shelf`: `git clone https://github.com/MHSantonu/spatialshelf.git /var/www/spatialshelf`.
4. Build the two server `.env` files from the local ones (never commit
   them): copy local `app/.env` and root `.env` over scp to a temp path,
   then on the server write the finals with overrides
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`,
   `DB_DATABASE=spatialshelf`, `DB_USERNAME=spatialshelf`,
   `DB_PASSWORD=<new password>`,
   `SHARED_STORAGE_PATH=/var/www/spatialshelf/storage-shared`,
   `WORKER_URL=http://127.0.0.1:8080`. Delete the temp copies.
   Permissions: `chmod 640`, owner `shelf:www-data`.
5. `php artisan key:generate`, then `bash deploy/deploy.sh`
   (composer, npm build, migrate, worker venv + DINOv2).
6. `php artisan migrate --seed --force` for the demo shop + users.
7. `bash deploy/deploy.sh install-supervisor`, then
   `bash deploy/deploy.sh install-nginx <domain>`.
8. `sudo certbot --nginx -d <domain> --redirect` (agree + your email).
9. Checks: `sudo supervisorctl status`, worker `/health`, homepage over
   https, WhatsApp verify handshake (below).

## Redeploy after future pushes

On the server as `shelf`:

```bash
cd /var/www/spatialshelf
git pull --ff-only
bash deploy/deploy.sh
```

`deploy.sh` rebuilds, migrates and restarts the queue + worker. Nginx
and certbot are untouched. If the machine itself was rebuilt, start
again from "First deploy".

## Logs

| What | Where |
| --- | --- |
| Laravel | `/var/www/spatialshelf/app/storage/logs/laravel.log` |
| Queue | `/var/log/spatialshelf-queue.log` |
| Worker | `/var/log/spatialshelf-worker.log` |
| Nginx access / error | `/var/log/nginx/access.log`, `/var/log/nginx/error.log` |
| Supervisor | `sudo supervisorctl tail spatialshelf-queue` (or `-worker`) |
| Boot services | `systemctl status php8.4-fpm nginx mysql supervisor` |

## WhatsApp

Callback URL to paste into the Meta app dashboard (webhook subscribe):

```text
https://<domain>/webhooks/whatsapp
```

Subscribe to the `messages` field; the verify token is `WA_VERIFY_TOKEN`
on the server. Verify-handshake test (token read on the server, never
printed):

```bash
ssh shelf@HOST 'TOKEN=$(grep -m1 ^WA_VERIFY_TOKEN= /var/www/spatialshelf/.env | cut -d= -f2-); curl -s "http://127.0.0.1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=$TOKEN&hub.challenge=TEST123"'
```

Expect `TEST123` back.

## Live test checklist

- [ ] `https://<domain>/` loads (welcome page, no exception).
- [ ] `https://<domain>/try` renders the order simulator.
- [ ] Worker healthy: `curl http://127.0.0.1:8080/health` → `{"ok": true, ...}`.
- [ ] `sudo supervisorctl status` shows both programs RUNNING.
- [ ] Login works with the seeded demo user; `/scan` page loads.
- [ ] Queue drains: upload a scan (or dispatch a test job) and watch it finish.
- [ ] WhatsApp verify handshake returns the challenge (command above).
- [ ] Send a real WhatsApp message; invoice arrives; confirm decrements stock.

## Progress note

<!-- Updated at the end of the first deploy. -->

- Status: LIVE since Oct 31, 2026 — https://159-65-146-210.sslip.io
  (droplet `spatialshelf`, blr1, s-2vcpu-4gb, 159.65.146.210).
- Deploy notes: server runs PHP 8.4 (Ondrej PPA, composer.lock needs
  ≥ 8.4 — not 8.3); seed with `config:clear` first (DemoShopSeeder
  reads env, seed, then re-cache). Test checklist below all verified
  except a real scan upload and a real WhatsApp order.

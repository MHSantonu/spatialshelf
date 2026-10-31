#!/usr/bin/env bash
# SpatialShelf deploy. Run as user `shelf` on the server from a clone of
# this repo at /var/www/spatialshelf:
#
#   bash deploy/deploy.sh            # build + migrate + restart services
#   bash deploy/deploy.sh install-nginx DOMAIN
#                                    # one-time: install the nginx site
#   bash deploy/deploy.sh install-supervisor
#                                    # one-time: install supervisor programs
#
# The one-time actions need sudo (shelf has NOPASSWD sudo from server-setup.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/app"
export COMPOSER_NO_INTERACTION=1

install_nginx() {
  local domain="${1:?usage: deploy.sh install-nginx DOMAIN}"
  sudo cp "$ROOT/deploy/nginx-spatialshelf.conf" /etc/nginx/sites-available/spatialshelf
  sudo sed -i "s/DOMAIN/${domain}/g" /etc/nginx/sites-available/spatialshelf
  sudo ln -sfn /etc/nginx/sites-available/spatialshelf /etc/nginx/sites-enabled/spatialshelf
  sudo rm -f /etc/nginx/sites-enabled/default
  sudo nginx -t
  sudo systemctl reload nginx
  echo "nginx site installed for ${domain}"
}

install_supervisor() {
  sudo cp "$ROOT/deploy/supervisor-queue.conf" /etc/supervisor/conf.d/spatialshelf-queue.conf
  sudo cp "$ROOT/deploy/supervisor-worker.conf" /etc/supervisor/conf.d/spatialshelf-worker.conf
  sudo supervisorctl reread
  sudo supervisorctl update
  echo "supervisor programs installed"
}

case "${1:-deploy}" in
  install-nginx) install_nginx "${2:-}"; exit 0 ;;
  install-supervisor) install_supervisor; exit 0 ;;
  deploy) ;;
  *) echo "unknown action: $1" >&2; exit 1 ;;
esac

echo "==> Laravel: composer install"
cd "$APP"
composer install --no-dev --optimize-autoloader

echo "==> Laravel: npm build"
npm ci --no-audit --no-fund
npm run build
rm -f "$APP/public/hot"

echo "==> Laravel: migrate"
php artisan migrate --force

echo "==> Laravel: caches + permissions"
php artisan config:cache
php artisan route:cache
php artisan view:cache
mkdir -p "$ROOT/storage-shared"
# Web server (www-data) and shelf share group access to writable dirs.
sudo chown -R shelf:www-data "$APP/storage" "$APP/bootstrap/cache" "$ROOT/storage-shared"
sudo chmod -R 775 "$APP/storage" "$APP/bootstrap/cache" "$ROOT/storage-shared"

echo "==> worker: venv (Python 3.11, torch CPU)"
cd "$ROOT/worker"
if [ ! -x "$ROOT/worker/.venv/bin/python" ]; then
  python3.11 -m venv "$ROOT/worker/.venv"
fi
"$ROOT/worker/.venv/bin/python" -m pip install --upgrade pip
"$ROOT/worker/.venv/bin/python" -m pip install -r "$ROOT/worker/requirements.txt" \
  --extra-index-url https://download.pytorch.org/whl/cpu
echo "==> worker: pre-download facebook/dinov2-small"
"$ROOT/worker/.venv/bin/python" -c \
  "from transformers import AutoModel, AutoImageProcessor; AutoImageProcessor.from_pretrained('facebook/dinov2-small'); AutoModel.from_pretrained('facebook/dinov2-small')"

echo "==> restart services"
if sudo supervisorctl status spatialshelf-queue >/dev/null 2>&1; then
  sudo supervisorctl restart spatialshelf-queue:
  sudo supervisorctl restart spatialshelf-worker:
else
  echo "(supervisor programs not installed yet — run: bash deploy/deploy.sh install-supervisor)"
fi

echo
echo "Deploy done."

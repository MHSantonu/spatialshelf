#!/usr/bin/env bash
# Build the server's .env files from local copies. Run once as `shelf`:
#   1. scp local app/.env  -> /home/shelf/.deploy-tmp/app.env
#   2. scp local root .env  -> /home/shelf/.deploy-tmp/root.env
#   3. echo "$DB_PASSWORD" | bash deploy/build-env.sh   (password via stdin,
#      never on the command line, never printed)
# Writes /var/www/spatialshelf/app/.env and /var/www/spatialshelf/.env with
# production overrides, fixes ownership/perms, deletes the temp copies.
set -euo pipefail

DOMAIN="${DOMAIN:-159-65-146-210.sslip.io}"
DBP="$(tr -d '\r\n')"
[ "${#DBP}" -ge 16 ] || { echo BAD_PW_LEN >&2; exit 1; }

T=/home/shelf/.deploy-tmp
[ -s "$T/app.env" ] && [ -s "$T/root.env" ] || { echo TMP_MISSING >&2; exit 1; }

set_kv() {
  local f="$1" k="$2" v="$3"
  if grep -q "^${k}=" "$f"; then
    sed -i "s|^${k}=.*|${k}=${v}|" "$f"
  else
    printf '%s=%s\n' "$k" "$v" >> "$f"
  fi
}

A=/var/www/spatialshelf/app/.env
R=/var/www/spatialshelf/.env
cp "$T/app.env" "$A"
cp "$T/root.env" "$R"

set_kv "$A" APP_ENV production
set_kv "$A" APP_DEBUG false
set_kv "$A" APP_URL "https://${DOMAIN}"
set_kv "$A" DB_CONNECTION mysql
set_kv "$A" DB_HOST 127.0.0.1
set_kv "$A" DB_PORT 3306
set_kv "$A" DB_DATABASE spatialshelf
set_kv "$A" DB_USERNAME spatialshelf
set_kv "$A" DB_PASSWORD "$DBP"
set_kv "$A" SHARED_STORAGE_PATH /var/www/spatialshelf/storage-shared
set_kv "$A" WORKER_URL http://127.0.0.1:8080

set_kv "$R" DB_DATABASE spatialshelf
set_kv "$R" DB_USERNAME spatialshelf
set_kv "$R" DB_PASSWORD "$DBP"
set_kv "$R" SHARED_STORAGE_PATH /var/www/spatialshelf/storage-shared
set_kv "$R" WORKER_URL http://127.0.0.1:8080

rm -rf "$T"
sudo chown shelf:www-data "$A" "$R"
sudo chmod 640 "$A" "$R"

echo ENV_OK
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_DATABASE|DB_USERNAME|SHARED_STORAGE_PATH|WORKER_URL)=' "$A"
awk -F= '/^DB_PASSWORD=/{print "DB_PASSWORD_LEN="length($2)}' "$A" "$R"
stat -c '%a %U:%G %n' "$A" "$R"

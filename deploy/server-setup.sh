#!/usr/bin/env bash
# SpatialShelf one-time server setup. Run ONCE as root on a fresh
# Ubuntu 24.04 droplet:  bash server-setup.sh
#
# Installs: user shelf (+ your key), ufw, 2 GB swap, nginx, MySQL 8,
# PHP 8.3 (+ fpm, composer), Python 3.11 (venv), Node 20, ffmpeg,
# supervisor, certbot. Creates DB + user `spatialshelf` and prints the
# generated MySQL password ONCE at the end — copy it, it is not stored.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root: bash server-setup.sh" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> apt base"
apt-get update
apt-get upgrade -y
apt-get install -y --no-install-recommends \
  ca-certificates curl git lsb-release software-properties-common \
  unzip zip build-essential pkg-config ufw gnupg

echo "==> user shelf"
if ! id shelf >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" shelf
fi
install -d -m 700 -o shelf -g shelf /home/shelf/.ssh
if [ -f /root/.ssh/authorized_keys ]; then
  cp /root/.ssh/authorized_keys /home/shelf/.ssh/authorized_keys
  chown shelf:shelf /home/shelf/.ssh/authorized_keys
  chmod 600 /home/shelf/.ssh/authorized_keys
fi
echo "shelf ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/shelf
chmod 440 /etc/sudoers.d/shelf
# Deployer conveniences (single-purpose box).
loginctl enable-linger shelf 2>/dev/null || true

echo "==> firewall (OpenSSH, 80, 443)"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "==> 2 GB swap"
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo "/swapfile none swap sw 0 0" >> /etc/fstab
fi

echo "==> nginx"
apt-get install -y nginx

echo "==> MySQL 8"
apt-get install -y mysql-server

echo "==> PHP 8.4 via Ondrej PPA (+ fpm + extensions)"
# composer.lock needs PHP >= 8.4 (dev runs 8.4); Ubuntu 24.04 ships 8.3.
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
  php8.4 php8.4-cli php8.4-fpm php8.4-mysql php8.4-xml php8.4-mbstring \
  php8.4-curl php8.4-zip php8.4-gd php8.4-bcmath php8.4-intl \
  php8.4-opcache php8.4-readline
update-alternatives --set php /usr/bin/php8.4
# Shelf videos upload through PHP: allow 200 MB like local `composer serve`.
printf '%s\n' \
  "upload_max_filesize = 200M" \
  "post_max_size = 200M" \
  "max_execution_time = 600" \
  "max_input_time = 600" \
  "memory_limit = 512M" > /etc/php/8.4/fpm/conf.d/99-spatialshelf.ini
printf '%s\n' "memory_limit = 1G" > /etc/php/8.4/cli/conf.d/99-spatialshelf.ini
systemctl enable --now php8.4-fpm

echo "==> composer"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL_SIG="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]; then
    echo "Composer installer signature mismatch" >&2
    exit 1
  fi
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi
sudo -u shelf composer --version

echo "==> Python 3.11 (deadsnakes; Ubuntu 24.04 ships 3.12)"
add-apt-repository -y ppa:deadsnakes/ppa
apt-get update
apt-get install -y python3.11 python3.11-venv python3.11-dev

echo "==> Node 20 (NodeSource)"
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

echo "==> ffmpeg, supervisor, certbot"
apt-get install -y ffmpeg supervisor certbot python3-certbot-nginx
systemctl enable --now supervisor

echo "==> MySQL: database + user spatialshelf"
systemctl enable --now mysql
# Wait for mysqld to accept connections (fresh installs can be slow).
for _ in $(seq 1 30); do
  mysqladmin ping --silent 2>/dev/null && break
  sleep 2
done
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS spatialshelf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'spatialshelf'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'spatialshelf'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON spatialshelf.* TO 'spatialshelf'@'localhost';
FLUSH PRIVILEGES;
SQL

echo
echo "Server setup complete."
echo "MySQL user spatialshelf / database spatialshelf are ready."
echo "MYSQL_SPATIALSHELF_PASSWORD=${DB_PASS}"
echo "(Copy it now — it is printed only this once.)"

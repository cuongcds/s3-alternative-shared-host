#!/bin/sh
set -e

echo "[entrypoint] waiting for mysql at ${DB_HOST:-host.docker.internal}:${DB_PORT:-3306}..."
for i in $(seq 1 30); do
  if php -r "exit(@fsockopen('${DB_HOST:-host.docker.internal}', ${DB_PORT:-3306}) ? 0 : 1);"; then
    break
  fi
  sleep 2
done

echo "[entrypoint] running migrations..."
php /var/www/html/cli/migrate.php

echo "[entrypoint] seeding access key..."
php /var/www/html/cli/seed.php

# The object storage volume is created root-owned by Docker on first run;
# php-fpm workers run as www-data and need write access to it.
mkdir -p "${STORAGE_ROOT:-/data}"
chown www-data:www-data "${STORAGE_ROOT:-/data}"

echo "[entrypoint] starting php-fpm"
exec php-fpm

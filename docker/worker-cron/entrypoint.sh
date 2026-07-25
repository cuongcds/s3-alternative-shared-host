#!/bin/sh
set -e

echo "[entrypoint] waiting for mysql at ${DB_HOST:-host.docker.internal}:${DB_PORT:-3306}..."
for i in $(seq 1 30); do
  if php -r "exit(@fsockopen('${DB_HOST:-host.docker.internal}', ${DB_PORT:-3306}) ? 0 : 1);"; then
    break
  fi
  sleep 2
done

# cron strips the container's environment from job processes by default;
# dump it (shell-escaped) to a file the crontab entry sources before running
# php, so cli/worker_cron.php's getenv() calls (DB_HOST, REDIS_PASSWORD, ...)
# see the same config as every other container.
php -r '
foreach (getenv() as $k => $v) {
    if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/", $k)) {
        echo "export " . $k . "=" . escapeshellarg($v) . "\n";
    }
}
' > /etc/container_env.sh

echo "[entrypoint] starting cron (worker runs on a schedule, see docker/worker-cron/crontab)"
exec cron -f

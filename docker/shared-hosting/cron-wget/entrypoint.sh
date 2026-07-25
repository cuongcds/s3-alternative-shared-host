#!/bin/sh
set -e

# Deliberately minimal: no PHP, no app code in this container at all — this
# simulates a cPanel-style cron job that can only `wget` a URL on a
# schedule, nothing else. The target host name ("apache-shared") is this
# compose file's other service, resolved via Docker's built-in DNS.

TARGET_URL="http://apache-shared/cronjobs/process?token=${CRON_SECRET}"

# /etc/cron.d/ entries are picked up automatically by the cron daemon on
# their own — do NOT also `crontab` this file: that installs it a second
# time as root's personal crontab too, which uses a different format (no
# leading username field) and fails with "root: not found" every tick.
cat > /etc/cron.d/open-s3-cron <<EOF
* * * * * root wget -q -O /dev/null "${TARGET_URL}" >> /proc/1/fd/1 2>> /proc/1/fd/2
EOF
chmod 0644 /etc/cron.d/open-s3-cron

echo "[entrypoint] starting cron — wget's /cronjobs/process every minute"
exec cron -f

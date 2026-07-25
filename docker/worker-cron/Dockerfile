FROM php:8.1-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpng-dev libjpeg62-turbo-dev libzip-dev cron \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install mysqli gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .

COPY docker/worker-cron/crontab /etc/cron.d/open-s3-worker
# /etc/cron.d/ entries are picked up automatically by the cron daemon on
# their own — do NOT also `crontab` this file: that installs it a second
# time as root's personal crontab too, which uses a different format (no
# leading username field) and fails with "root: not found" every tick.
RUN chmod 0644 /etc/cron.d/open-s3-worker \
    && chmod +x docker/worker-cron/entrypoint.sh

ENTRYPOINT ["docker/worker-cron/entrypoint.sh"]

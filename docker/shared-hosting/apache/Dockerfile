# Deploy option 3 simulation: a plain Apache+mod_php image with NO custom
# entrypoint at all — real shared hosting gives you no hook to run anything
# on container/process boot, so this doesn't get one either (contrast with
# docker/apache/Dockerfile, option 2, which waits for MySQL and runs
# migrate.php/seed.php on every start). Schema import and .env are manual
# steps — see README "Option 3: deploying to real shared hosting".
#
# Storage is a plain folder inside the project (storage/private/, bind-
# mounted by docker-compose.shared-hosting.yml — see STORAGE_ROOT there),
# not a Docker-managed volume, since a real shared host has no such concept
# either — it's just a directory on disk.
FROM php:8.1-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpng-dev libjpeg62-turbo-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install mysqli gd \
    && a2enmod rewrite \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .

# Reuses option 2's vhost/php.ini — same Apache, same routing/upload-size
# needs, only the entrypoint and storage location differ for this option.
COPY docker/apache/000-default.conf /etc/apache2/sites-enabled/000-default.conf
COPY docker/apache/uploads.ini /usr/local/etc/php/conf.d/zzz-uploads.ini

RUN mkdir -p application/cache application/logs storage/private \
    && chown -R www-data:www-data application/cache application/logs storage/private

EXPOSE 80
# No ENTRYPOINT/CMD override — the base image's own apache2-foreground start
# is exactly what a real shared host's Apache would do on its own, and this
# option is meant to have no boot automation whatsoever.

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

COPY docker/apache/000-default.conf /etc/apache2/sites-enabled/000-default.conf
COPY docker/apache/uploads.ini /usr/local/etc/php/conf.d/zzz-uploads.ini

RUN mkdir -p application/cache application/logs /data \
    && chown -R www-data:www-data application/cache application/logs /data \
    && chmod +x docker/apache/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker/apache/entrypoint.sh"]

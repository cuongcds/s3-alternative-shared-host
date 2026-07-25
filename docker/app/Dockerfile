FROM php:8.1-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libpng-dev libjpeg62-turbo-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install mysqli gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .

# Admin panel CSS (docs/plans_v2.md section 9): Tailwind Standalone CLI, no
# Node/npm in the runtime image. assets/admin.css is already committed
# (built locally the same way) so this just keeps it in sync with whatever
# admin view/JS changes shipped in this build. Pinned to v3 on purpose:
# admin.src.css/tailwind.config.js use v3's @tailwind-directive + JS-config
# idiom, and v4's CLI silently drops every theme-based utility (colors,
# spacing, responsive variants) when fed a v3-style input file instead of
# erroring — "latest" would build a nearly-empty, subtly broken stylesheet.
RUN curl -sLo /usr/local/bin/tailwindcss \
        https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.13/tailwindcss-linux-x64 \
    && chmod +x /usr/local/bin/tailwindcss \
    && tailwindcss -i assets/admin.src.css -o assets/admin.css --minify \
    && rm /usr/local/bin/tailwindcss

RUN mkdir -p application/cache application/logs \
    && chown -R www-data:www-data application/cache application/logs \
    && chmod +x docker/app/entrypoint.sh \
    && printf '[www]\nlisten = 0.0.0.0:9000\n' > /usr/local/etc/php-fpm.d/zzz-listen.conf

EXPOSE 9000
ENTRYPOINT ["docker/app/entrypoint.sh"]

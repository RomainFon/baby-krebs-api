# ==============================================================================
# Stage 1 — Composer dependencies
# ==============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ==============================================================================
# Stage 2 — PHP-FPM
# ==============================================================================
# Version patch épinglée : un tag flottant (8.4-fpm-alpine) resterait figé sur
# l'image en cache local au `--build`. 8.4.27 corrige PHP2Shell
# (CVE-2026-109618 / CVE-2026-109619, 21/10/2026).
FROM php:8.4.27-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS \
  && docker-php-ext-install \
    pdo_pgsql \
    intl \
    opcache \
    pcntl \
  && apk del $PHPIZE_DEPS

COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini
COPY docker/php/security.ini $PHP_INI_DIR/conf.d/security.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p bootstrap/cache resources/views \
  && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

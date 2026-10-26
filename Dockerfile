# Dockerfile multi-stage:
#   dev  → desarrollo local (código montado por volumen, pcov para coverage)
#   prod → imagen inmutable para staging/prod (código copiado, sin dev deps)
#   web  → nginx con la config de prod y el public/ de la app
# Cada compose elige su target explícitamente.

FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git \
    libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www


FROM base AS dev

# pcov: driver de coverage para los tests (php artisan test --coverage)
RUN pecl install pcov && docker-php-ext-enable pcov

COPY ./ /var/www

RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www

EXPOSE 9000
CMD ["php-fpm"]


FROM base AS prod

RUN docker-php-ext-install opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini

# Dependencias primero: esta capa se cachea mientras no cambie composer.lock
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY ./ /var/www
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web

COPY docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public

# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4

FROM composer:2.8 AS vendor-development
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader

FROM composer:2.8 AS vendor-production
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --classmap-authoritative

FROM php:${PHP_VERSION}-fpm-alpine AS php-runtime

ARG APP_UID=10001
ARG APP_GID=10001

RUN apk add --no-cache \
        fcgi \
        icu-libs \
        libpq \
        libsodium \
        libzip \
        oniguruma \
        sqlite-libs \
    && apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libsodium-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
    && docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        pdo_sqlite \
        sodium \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-dependencies \
    && rm -rf /tmp/pear /var/cache/apk/* \
    && addgroup -g "${APP_GID}" -S nexasalud \
    && adduser -u "${APP_UID}" -S -D -H -G nexasalud nexasalud

WORKDIR /var/www/html

COPY --chown=nexasalud:nexasalud artisan composer.json composer.lock phpunit.xml ./
COPY --chown=nexasalud:nexasalud app ./app
COPY --chown=nexasalud:nexasalud bootstrap ./bootstrap
COPY --chown=nexasalud:nexasalud config ./config
COPY --chown=nexasalud:nexasalud database ./database
COPY --chown=nexasalud:nexasalud public ./public
COPY --chown=nexasalud:nexasalud resources ./resources
COPY --chown=nexasalud:nexasalud routes ./routes
COPY --chown=nexasalud:nexasalud storage ./storage

COPY docker/php.ini /usr/local/etc/php/conf.d/99-nexasalud.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-nexasalud.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/nexasalud-entrypoint

RUN mkdir -p \
        bootstrap/cache \
        storage/app/clinical \
        storage/app/openapi \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R nexasalud:nexasalud bootstrap/cache storage

USER nexasalud

EXPOSE 9000

HEALTHCHECK --interval=10s --timeout=4s --start-period=30s --retries=6 \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q pong || exit 1

ENTRYPOINT ["nexasalud-entrypoint"]
CMD ["php-fpm", "-F"]

FROM php-runtime AS development
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY --from=vendor-development --chown=nexasalud:nexasalud /build/vendor ./vendor
COPY --chown=nexasalud:nexasalud tests ./tests

FROM php-runtime AS production
COPY --from=vendor-production --chown=nexasalud:nexasalud /build/vendor ./vendor

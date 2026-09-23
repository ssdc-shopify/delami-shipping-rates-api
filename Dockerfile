# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM php:8.4-apache-bookworm AS php-base

RUN apt-get update \
  && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    libicu-dev \
  && docker-php-ext-install -j"$(nproc)" \
    intl \
  && apt-get purge -y --auto-remove \
    icu-devtools \
    libicu-dev \
  && rm -rf /var/lib/apt/lists/*

FROM php-base AS build

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

RUN apt-get update \
  && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    git \
    unzip \
  && composer install \
  --no-dev \
  --no-interaction \
  --no-progress \
  --no-scripts \
  --prefer-dist \
  && rm -rf /var/lib/apt/lists/*

COPY . .

RUN composer install \
  --classmap-authoritative \
  --no-dev \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  && install -d -m 0770 \
    writable/cache \
    writable/database \
    writable/debugbar \
    writable/logs \
    writable/session \
    writable/uploads \
  && chown -R www-data:www-data writable

FROM php-base AS runtime

ENV CI_ENVIRONMENT=production

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-delami-production.ini
COPY docker/prepare-sqlite.php /usr/local/bin/prepare-sqlite.php
COPY --from=build --chown=www-data:www-data /var/www/html ./

RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
  && a2enmod headers remoteip rewrite \
  && install -d -o www-data -g www-data -m 0755 /var/run/apache2 /var/lock/apache2

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=18 \
  CMD curl --fail --silent --show-error --max-time 5 --header 'X-Forwarded-Proto: https' http://127.0.0.1:8080/health >/dev/null || exit 1

CMD ["apache2-foreground"]

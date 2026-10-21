FROM composer:2.10.3 AS composer
FROM mlocati/php-extension-installer:2.12.3 AS php-extension-installer
FROM dunglas/frankenphp:1.13.2-php8.5.12-alpine AS php

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    gd \
    pdo_mysql \
    opcache

RUN apk add --no-cache curl tzdata

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY ./bin /app/bin
COPY ./migrations /app/migrations
COPY ./src /app/src
COPY ./Caddyfile /etc/frankenphp/Caddyfile
COPY ./docker/php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY ./docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod 0755 /usr/local/bin/docker-entrypoint.sh
COPY ./composer.json ./composer.lock* /app/

WORKDIR /app/

RUN rm -rf /app/vendor \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-progress --classmap-authoritative -d /app/ \
    && composer clear-cache

# Generated ORM hydrators/extractors, loaded by DbContext under opcache instead of an attribute scan.
RUN php bin/console orm:schema-dump

# The entrypoint applies pending migrations (the seed data lives in them), then execs the CMD.
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

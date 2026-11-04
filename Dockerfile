# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS base

ARG PHPREDIS_VERSION=6.3.0
ARG MONGODB_VERSION=2.5.3

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libfcgi-bin libpq-dev libicu-dev libonig-dev libzip-dev libssl-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl mbstring zip gd exif bcmath pcntl opcache \
    && MAKEFLAGS="-j2" pecl install "redis-${PHPREDIS_VERSION}" "mongodb-${MONGODB_VERSION}" \
    && docker-php-ext-enable redis mongodb \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Las extensiones no dependen de la identidad local y conservan su caché al cambiarla.
ARG APP_UID=1000
ARG APP_GID=1000
RUN test "${APP_UID}" -gt 0 && test "${APP_GID}" -gt 0 \
    || { echo 'APP_UID y APP_GID deben ser positivos; usa el UID/GID de tu usuario local.' >&2; exit 1; }
RUN groupadd --gid "${APP_GID}" sysforest \
    && useradd --uid "${APP_UID}" --gid sysforest --create-home --shell /bin/bash sysforest

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-sysforest.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-sysforest.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/sysforest-entrypoint
COPY --chmod=755 docker/php/fpm-healthcheck.sh /usr/local/bin/sysforest-fpm-healthcheck
COPY --chmod=755 docker/php/process-healthcheck.php /usr/local/bin/sysforest-process-healthcheck
COPY --chmod=755 docker/php/migrate.sh /usr/local/bin/sysforest-migrate

WORKDIR /var/www/html
RUN chown sysforest:sysforest /var/www/html
USER sysforest

FROM base AS runtime

# El lock se genera con el target base. El build conserva las dependencias de desarrollo.
COPY --chown=sysforest:sysforest composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY --chown=sysforest:sysforest . .
# Dotenv dispone de un archivo vacío; Compose inyecta la configuración real.
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && touch .env \
    && composer dump-autoload --no-interaction --optimize

EXPOSE 9000
ENTRYPOINT ["sysforest-entrypoint"]
CMD ["php-fpm", "-F"]


# Imagen de despliegue: el target runtime conserva el entorno local existente.
FROM base AS production
ENV APP_ENV=production APP_DEBUG=false
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-sysforest.ini
COPY docker/production/fpm.conf /usr/local/etc/php-fpm.d/zz-sysforest.conf
COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/sysforest-production-entrypoint
COPY --chown=sysforest:sysforest composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
# Lista explícita: nunca incorporar .env, respaldos, fotos locales ni herramientas de revisión.
COPY --chown=sysforest:sysforest app ./app
COPY --chown=sysforest:sysforest bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY --chown=sysforest:sysforest config ./config
COPY --chown=sysforest:sysforest database/migrations ./database/migrations
COPY --chown=sysforest:sysforest database/seeders ./database/seeders
COPY --chown=sysforest:sysforest public ./public
# Las bibliotecas públicas deben viajar completas en el paquete de despliegue.
RUN for asset in \
        jquery/jquery.min.js \
        select2/select2.min.js select2/select2.min.css select2/es.js \
        leaflet/leaflet.js leaflet/leaflet.css \
        leaflet/images/layers.png leaflet/images/layers-2x.png \
        leaflet/images/marker-icon.png leaflet/images/marker-icon-2x.png leaflet/images/marker-shadow.png \
        supercluster/supercluster.min.js leaflet-heat/leaflet-heat.js chartjs/chart.umd.min.js; do \
        test -s "public/vendor/$asset" \
            || { printf 'Falta un recurso público requerido: %s\n' "$asset" >&2; exit 1; }; \
    done
COPY --chown=sysforest:sysforest resources ./resources
COPY --chown=sysforest:sysforest routes ./routes
COPY --chown=sysforest:sysforest artisan ./artisan
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && touch .env \
    && composer dump-autoload --no-dev --no-interaction --optimize
EXPOSE 9000
ENTRYPOINT ["sysforest-production-entrypoint"]
CMD ["php-fpm", "-F"]

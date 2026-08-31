FROM php:8.5-fpm-alpine AS php-base

ARG PHPREDIS_COMMIT=df4fab2de7fc327c54c94a13af2b9542e4fbd720
ARG PHPREDIS_SHA256=79ecabd899e50a6efa56d9fc28a987a25d78deecc32fdd0fa9840b1b3d83740e

RUN set -eux; \
    apk add --no-cache clamav icu-libs libjpeg-turbo libpng libpq libwebp libzip; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev linux-headers postgresql-dev; \
    wget -O /tmp/phpredis.tar.gz "https://github.com/phpredis/phpredis/archive/${PHPREDIS_COMMIT}.tar.gz"; \
    echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/phpredis; \
    tar -xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j2 bcmath exif gd intl pcntl pdo_pgsql sockets zip; \
    cd /tmp/phpredis; \
    phpize; \
    ./configure --enable-redis; \
    make -j2; \
    make install; \
    docker-php-ext-enable redis; \
    php -r 'exit(extension_loaded("redis") && phpversion("redis") === "6.3.0" ? 0 : 1);'; \
    cd /; \
    rm -rf /tmp/phpredis /tmp/phpredis.tar.gz; \
    apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

FROM php-base AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader

FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php-base AS development
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM php-base AS production
ENV APP_ENV=production APP_DEBUG=false
COPY --from=vendor /app/vendor ./vendor
COPY artisan composer.json composer.lock ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY php-production.ini $PHP_INI_DIR/conf.d/zz-memoria-production.ini
COPY php-fpm-production.conf /usr/local/etc/php-fpm.d/zz-memoria-production.conf
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chown root:root /var/www/html \
    && chmod 0755 /var/www/html
USER www-data
CMD ["php-fpm", "-F"]

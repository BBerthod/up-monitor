# Stage 1: Install PHP dependencies
FROM composer:2 AS php-deps

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --no-progress \
    --no-scripts \
    --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:20-alpine AS assets

WORKDIR /var/www/html

ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=php-deps /var/www/html/vendor ./vendor
RUN npm run build

# Stage 3: Production image
FROM php:8.4-fpm-alpine AS production

RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    libpq \
    $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    pcntl \
    bcmath \
    opcache \
    zip \
    gd \
    intl

RUN pecl install redis && docker-php-ext-enable redis

WORKDIR /var/www/html

COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/supervisor/app.conf /etc/supervisor/conf.d/app.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/entrypoint/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

COPY . .
COPY --from=php-deps /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

RUN mkdir -p /var/log/supervisor /var/log/php /var/log/php-fpm

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://127.0.0.1:8000/health || exit 1

CMD ["/usr/local/bin/start.sh"]

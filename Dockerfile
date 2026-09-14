# Stage 1: Build JS/CSS assets (native Debian Node — no cross-platform binary issues)
FROM node:22-bookworm-slim AS node-builder
WORKDIR /build
COPY package*.json vite.config.js ./
COPY resources/ ./resources/
RUN npm ci && npm run build

# Stage 2: PHP-FPM + Nginx runtime
FROM php:8.4-fpm

# System deps: nginx + PHP extension build deps
RUN apt-get update && apt-get install -y \
    nginx \
    libpq-dev \
    libicu-dev \
    zlib1g-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by the app (bcmath: VAPID signing em minishlink/web-push; gd: Dompdf fotos/evidencias)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl zip opcache bcmath gd

# Upload limits
RUN { \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 128M'; \
    echo 'memory_limit = 256M'; \
} > /usr/local/etc/php/conf.d/uploads.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Application source
COPY . .
# Compiled front-end assets from the node builder stage
COPY --from=node-builder /build/public/build ./public/build

# PHP dependencies (scripts skipped — package:discover runs at startup with env present)
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Nginx site template + startup script (normalise CRLF -> LF for bash/sed safety)
COPY nginx-site.template /etc/nginx/nginx-site.template
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh /etc/nginx/nginx-site.template \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default

# Writable dirs
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

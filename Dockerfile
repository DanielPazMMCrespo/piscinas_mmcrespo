# Stage 1: Build JS assets (Node.js native, no cross-platform issues)
FROM node:22-bookworm-slim AS node-builder
WORKDIR /build
COPY package*.json ./
RUN npm ci
COPY resources/ ./resources/
COPY vite.config.js ./
COPY postcss.config.js* ./
COPY tailwind.config.js* ./
RUN npm run build

# Stage 2: PHP runtime (no Node.js — avoids MPM conflict)
FROM php:8.4-apache

# Install PHP extension dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    zlib1g-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql intl zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable mod_rewrite
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy application code
COPY . .

# Copy compiled assets from node-builder
COPY --from=node-builder /build/public/build ./public/build

# Install PHP dependencies only
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Fix permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Configure Apache
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

EXPOSE 80
CMD ["apache2-foreground"]

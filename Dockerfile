FROM heroku/heroku:24-build as builder

# Install PHP 8.3 with required extensions
RUN apt-get update && apt-get install -y \
    php8.3 \
    php8.3-cli \
    php8.3-fpm \
    php8.3-intl \
    php8.3-zip \
    php8.3-pgsql \
    php8.3-redis \
    composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Build assets
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && npm ci \
    && npm run build

FROM heroku/heroku:24 as runtime

# Install PHP runtime with extensions
RUN apt-get update && apt-get install -y \
    php8.3 \
    php8.3-cli \
    php8.3-fpm \
    php8.3-intl \
    php8.3-zip \
    php8.3-pgsql \
    php8.3-redis \
    apache2 \
    libapache2-mod-php8.3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=builder /app .

# Apache configuration
RUN a2enmod rewrite
RUN sed -i 's|/var/www/html|/app/public|g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]

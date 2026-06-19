FROM node:22-alpine as node-builder
WORKDIR /build
COPY package*.json ./
RUN npm ci

FROM php:8.3-apache

# Install system dependencies for PostgreSQL + intl + zip extensions
RUN apt-get update && apt-get install -y \
    postgresql-client \
    libpq-dev \
    libicu-dev \
    zlib1g-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql intl zip \
    && docker-php-ext-enable pdo pdo_pgsql intl zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY . .

# Copy built assets from Node builder
COPY --from=node-builder /build/node_modules ./node_modules

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Build assets
RUN npm run build

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Configure Apache
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf
RUN echo '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>' >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]

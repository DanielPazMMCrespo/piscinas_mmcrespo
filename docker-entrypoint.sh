#!/bin/bash
set -e

cd /var/www/html

# --- 1. Bind nginx to Railway's public target port (default: 9000 per Railway settings) ---
PORT="${PORT:-9000}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx-site.template > /etc/nginx/sites-enabled/default
echo "[entrypoint] nginx will listen on port ${PORT}"

# --- 2. Move php-fpm to a Unix socket (avoids any TCP port collision with nginx) ---
FPM_SOCK="/run/php-fpm.sock"
# Override the listen directive regardless of what value is already there
sed -i "s|^listen = .*|listen = ${FPM_SOCK}|" /usr/local/etc/php-fpm.d/www.conf
# Ensure socket is accessible by nginx (www-data user)
grep -q "^listen.owner" /usr/local/etc/php-fpm.d/www.conf \
    && sed -i "s|^listen.owner = .*|listen.owner = www-data|" /usr/local/etc/php-fpm.d/www.conf \
    || echo "listen.owner = www-data" >> /usr/local/etc/php-fpm.d/www.conf
grep -q "^listen.group" /usr/local/etc/php-fpm.d/www.conf \
    && sed -i "s|^listen.group = .*|listen.group = www-data|" /usr/local/etc/php-fpm.d/www.conf \
    || echo "listen.group = www-data" >> /usr/local/etc/php-fpm.d/www.conf
echo "[entrypoint] php-fpm will use Unix socket: ${FPM_SOCK}"

# Verify the change took effect
grep "^listen" /usr/local/etc/php-fpm.d/www.conf || true

# --- 3. Ensure writable storage structure + permissions ---
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

# --- 4. Laravel runtime setup ---
php artisan package:discover --ansi || true
php artisan storage:link || true
php artisan migrate --force || echo "[entrypoint] WARNING: migrate failed (continuing)"
php artisan filament:assets || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# --- 5. Start php-fpm in background ---
php-fpm --nodaemonize &
FPM_PID=$!

echo "[entrypoint] waiting for php-fpm socket at ${FPM_SOCK}..."
READY=0
for i in $(seq 1 60); do
    if [ -S "${FPM_SOCK}" ]; then
        READY=1
        echo "[entrypoint] php-fpm socket ready after ${i} attempts"
        break
    fi
    sleep 0.5
done

if [ "$READY" -eq 0 ]; then
    echo "[entrypoint] ERROR: php-fpm socket never appeared — check php-fpm config"
    exit 1
fi

# --- 6. Start nginx (foreground = PID 1 keeper) ---
echo "[entrypoint] starting nginx on port ${PORT}"
exec nginx -g 'daemon off;'

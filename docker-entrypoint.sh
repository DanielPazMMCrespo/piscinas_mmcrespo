#!/bin/bash
set -e

cd /var/www/html

# --- 1. Bind nginx to Railway's dynamic PORT ---
PORT="${PORT:-80}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx-site.template > /etc/nginx/sites-enabled/default
echo "[entrypoint] nginx will listen on port ${PORT}"

# --- 2. Ensure writable storage structure + permissions ---
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

# --- 3. Laravel runtime setup ---
php artisan package:discover --ansi || true
php artisan storage:link || true
php artisan migrate --force || echo "[entrypoint] WARNING: migrate failed (continuing)"
php artisan filament:assets || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# --- 4. Start php-fpm in background ---
php-fpm --nodaemonize &
FPM_PID=$!

echo "[entrypoint] waiting for php-fpm to bind on :9000..."

# Wait until php-fpm is actually accepting TCP connections on 9000
# Uses bash built-in /dev/tcp — no extra packages needed
READY=0
for i in $(seq 1 60); do
    if (echo > /dev/tcp/127.0.0.1/9000) 2>/dev/null; then
        READY=1
        echo "[entrypoint] php-fpm ready after ${i} attempts"
        break
    fi
    sleep 0.5
done

if [ "$READY" -eq 0 ]; then
    echo "[entrypoint] ERROR: php-fpm did not start in 30s"
    exit 1
fi

# --- 5. Start nginx (foreground = PID 1 keeper) ---
echo "[entrypoint] starting nginx"
exec nginx -g 'daemon off;'

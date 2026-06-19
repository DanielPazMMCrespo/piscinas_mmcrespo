#!/bin/bash
set -e

cd /var/www/html

# --- 1. Bind nginx to the port Railway routes to.
# Railway's Public Networking forwards the edge to a fixed target port.
# Default to 9000 (Railway's current target) but honour $PORT if Railway sets it.
PORT="${PORT:-9000}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx-site.template > /etc/nginx/sites-enabled/default
echo "[entrypoint] nginx will listen on port ${PORT}"

# --- 2. Move php-fpm OFF 9000 so it never collides with the public HTTP port.
# php-fpm now speaks FastCGI on 127.0.0.1:9001; nginx proxies to it.
sed -i 's/^listen = .*/listen = 127.0.0.1:9001/' /usr/local/etc/php-fpm.d/www.conf
echo "[entrypoint] php-fpm will listen on 127.0.0.1:9001 (FastCGI)"

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

echo "[entrypoint] waiting for php-fpm to bind on :9001..."
READY=0
for i in $(seq 1 60); do
    if (echo > /dev/tcp/127.0.0.1/9001) 2>/dev/null; then
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

# --- 6. Start nginx (foreground = PID 1 keeper) ---
echo "[entrypoint] starting nginx on port ${PORT}"
exec nginx -g 'daemon off;'

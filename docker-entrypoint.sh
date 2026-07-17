#!/bin/bash
set -e

cd /var/www/html

# --- 0. Worker / one-off mode ------------------------------------------------
# Railway appends a service's "Custom Start Command" as arguments to this
# ENTRYPOINT. The queue worker service runs the SAME image but must NOT start
# nginx/php-fpm, and must NOT migrate/seed (the web service owns schema changes).
# When invoked with any arguments (e.g. `php artisan queue:work ...`), run them
# directly after a minimal, read-only setup.
if [ "$#" -gt 0 ] && [ "$1" != "php-fpm" ]; then
    echo "[entrypoint] command override detected — worker mode: $*"
    mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
    chown -R www-data:www-data storage bootstrap/cache || true
    php artisan config:cache || true
    exec "$@"
fi

# --- 1. Bind nginx to Railway's public target port (Railway routes public HTTP here) ---
PORT="${PORT:-9000}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx-site.template > /etc/nginx/sites-enabled/default
echo "[entrypoint] nginx will listen on port ${PORT}"

# --- 2. Force php-fpm onto an INTERNAL port (9001) so it never collides with nginx ---
# The official php:fpm image ships zz-docker.conf which loads AFTER www.conf and
# overrides `listen`. We rewrite it directly — this is the file that actually wins.
cat > /usr/local/etc/php-fpm.d/zz-docker.conf <<'EOF'
[global]
daemonize = no

[www]
listen = 127.0.0.1:9001
EOF
echo "[entrypoint] php-fpm forced to 127.0.0.1:9001 (via zz-docker.conf)"

# --- 3. Ensure writable storage structure + permissions ---
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs storage/app/private/livewire-tmp storage/app/public
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R 777 storage/logs storage/framework || true

# --- 4. Laravel runtime setup ---
php artisan package:discover --ansi || true
php artisan storage:link || true
mkdir -p storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap
chmod -R 777 storage bootstrap
# Verify storage/logs is writable
if [ ! -w storage/logs ]; then
    echo "[entrypoint] ERROR: storage/logs is not writable after chmod — this will cause logging failures"
    ls -la storage/ | head -20
    exit 1
fi
php artisan migrate --force || echo "[entrypoint] WARNING: migrate failed (continuing)"
php artisan db:seed --force || echo "[entrypoint] WARNING: db:seed failed (continuing)"
php artisan filament:assets || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
php artisan filament:optimize || true

# --- 5. Start scheduler in background (runs schedule:run every minute) ---
(while true; do php artisan schedule:run; sleep 60; done) &
echo "[entrypoint] scheduler started (resilient bash loop, PID $!)"

# --- 6. Start php-fpm in background ---
php-fpm --nodaemonize &
FPM_PID=$!

echo "[entrypoint] waiting for php-fpm on 127.0.0.1:9001..."
READY=0
for i in $(seq 1 60); do
    if (echo > /dev/tcp/127.0.0.1/9001) 2>/dev/null; then
        READY=1
        echo "[entrypoint] php-fpm ready on :9001 after ${i} attempts"
        break
    fi
    sleep 0.5
done

if [ "$READY" -eq 0 ]; then
    echo "[entrypoint] ERROR: php-fpm never bound 127.0.0.1:9001"
    exit 1
fi

# --- 6. Start nginx (foreground = PID 1 keeper) ---
echo "[entrypoint] starting nginx on port ${PORT}"
exec nginx -g 'daemon off;'

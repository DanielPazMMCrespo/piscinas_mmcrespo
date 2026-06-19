#!/bin/bash
set -e

cd /var/www/html

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

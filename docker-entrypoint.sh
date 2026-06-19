#!/bin/bash
# Robust startup for Laravel on Railway (nginx + php-fpm).
# Non-fatal artisan steps: if one fails the server still boots so the
# error is visible in the app instead of crash-looping the container.
set -e

cd /var/www/html

# --- 1. Bind nginx to Railway's dynamic PORT (defaults to 80 locally) ---
PORT="${PORT:-80}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx-site.template > /etc/nginx/sites-enabled/default
echo "[entrypoint] nginx will listen on port ${PORT}"

# --- 2. Ensure writable storage structure + permissions ---
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
chown -R www-data:www-data storage bootstrap/cache || true

# --- 3. Laravel runtime setup (env vars are available now, not at build) ---
php artisan package:discover --ansi || true
php artisan storage:link || true
php artisan migrate --force || echo "[entrypoint] WARNING: migrate failed (continuing)"
php artisan filament:assets || true

# Cache config/routes/views for production performance.
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# --- 4. Start php-fpm (background) then nginx (foreground = PID 1 keeper) ---
php-fpm --nodaemonize &
FPM_PID=$!

# Wait until php-fpm is accepting connections on :9000 before nginx starts
for i in $(seq 1 30); do
    if php-fpm -t >/dev/null 2>&1 && kill -0 "$FPM_PID" 2>/dev/null; then
        break
    fi
    sleep 0.5
done

echo "[entrypoint] starting nginx"
exec nginx -g 'daemon off;'

#!/bin/bash
set -e

# Start php-fpm in background
php-fpm --nodaemonize &
PHP_FPM_PID=$!

# Wait for php-fpm socket to be ready
sleep 2

# Start nginx in foreground
exec nginx -g 'daemon off;'

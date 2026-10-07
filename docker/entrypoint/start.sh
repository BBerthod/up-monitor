#!/bin/sh
set -e

cd /var/www/html

# Clear stale cache and discover packages
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi

# Run migrations (don't crash container on failure — allows recovery)
echo "Running migrations..."
if php artisan migrate --force; then
    echo "Migrations completed successfully."
else
    echo "WARNING: Migrations failed (exit code $?). Container will start anyway."
    echo "Check database state and re-run migrations manually if needed."
fi

# Cache config/routes/views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start supervisor
exec supervisord -c /etc/supervisord.conf

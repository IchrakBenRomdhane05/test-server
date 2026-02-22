#!/bin/bash
set -e

# Set PORT (Render provides this, default to 10000)
PORT="${PORT:-10000}"

# Configure Apache to listen on the correct port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

echo "Laravel starting on port ${PORT}..."

# Generate app key if not set
php artisan key:generate --force --no-interaction 2>/dev/null || true

echo "Caching configuration..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Running migrations..."
php artisan migrate --force || true

echo "Starting Apache on port ${PORT}..."
apache2-foreground

#!/bin/sh
set -e

# Espera a PostgreSQL y aplica migraciones pendientes al iniciar
until php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
  echo "Esperando a PostgreSQL..."
  sleep 2
done

php artisan migrate --force
php artisan config:cache

exec "$@"

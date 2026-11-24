#!/bin/sh
set -e

as_app() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

chown www-data:www-data /data

# Anahtar verilmediyse bir kez üretilir ve veriyle birlikte saklanır.
if [ -z "$APP_KEY" ]; then
    if [ ! -s /data/app.key ]; then
        (umask 077 && echo "base64:$(head -c 32 /dev/urandom | base64)" > /data/app.key)
    fi
    APP_KEY="$(cat /data/app.key)"
    export APP_KEY
fi

# Örnek veri yalnızca veritabanı ilk kez oluşturulurken eklenir.
if [ ! -f "$DB_DATABASE" ]; then
    as_app touch "$DB_DATABASE"
    as_app php artisan migrate --force --seed || { rm -f "$DB_DATABASE"*; exit 1; }
else
    chown www-data:www-data "$DB_DATABASE"
    as_app php artisan migrate --force
fi

as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan view:cache

exec docker-php-entrypoint "$@"

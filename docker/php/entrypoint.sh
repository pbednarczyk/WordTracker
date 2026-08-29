#!/bin/sh
set -e

mkdir -p /var/www/app/var/cache /var/www/app/var/log
chown -R www-data:www-data /var/www/app/var

exec docker-php-entrypoint "$@"

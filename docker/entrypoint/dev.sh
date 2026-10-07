#!/bin/sh
set -e

cd /var/www/html

composer install --no-interaction
npm install

exec "$@"

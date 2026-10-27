#!/bin/sh
# Prepares the uploads volume, then starts the given command (php-fpm).
set -eu

UPLOADS=/var/www/html/uploads

# A fresh volume is empty: add the default admin photo.
if [ ! -f "$UPLOADS/default.jpg" ]; then
    cp /usr/local/share/icq/default.jpg "$UPLOADS/default.jpg"
fi

# php-fpm runs as www-data and must be able to write new photos.
chown -R www-data:www-data "$UPLOADS"

exec docker-php-entrypoint "$@"

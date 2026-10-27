# PHP-FPM image for ICQ1.0
FROM php:8.3-fpm-alpine

# Helper that installs PHP extensions together with their system libraries.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pdo_mysql: database, gd + imagick: photos (imagick is needed for TIFF), exif: rotate phone photos.
# fcgi: provides cgi-fcgi for the container health check.
RUN install-php-extensions pdo_mysql gd exif imagick \
 && apk add --no-cache fcgi

COPY docker/php/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
# Placeholder photo of the default admin, copied into the uploads volume on start.
COPY uploads/default.jpg     /usr/local/share/icq/default.jpg
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

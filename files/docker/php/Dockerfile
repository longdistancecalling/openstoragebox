FROM php:8.3-apache

# pdo_mysql fehlt im Basis-Image, mbstring ist bereits eingebaut.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

RUN printf 'date.timezone = Europe/Berlin\n' > /usr/local/etc/php/conf.d/zz-app.ini \
    && printf 'display_errors = Off\nlog_errors = On\nerror_log = /dev/stderr\n' \
       >> /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

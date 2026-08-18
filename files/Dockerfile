FROM php:8.3-apache

# Das Basis-Image bringt PDO mit, aber nicht den MySQL-Treiber.
# mbstring wird für die Längenprüfung von UTF-8-Eingaben gebraucht.
RUN docker-php-ext-install pdo_mysql mbstring \
    && a2enmod rewrite headers

# Zeitzone, damit CURDATE()-Vergleiche und Datumsanzeige zusammenpassen.
RUN printf 'date.timezone = Europe/Berlin\n' > /usr/local/etc/php/conf.d/zz-app.ini \
    && printf 'display_errors = Off\nlog_errors = On\nerror_log = /dev/stderr\n' \
       >> /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

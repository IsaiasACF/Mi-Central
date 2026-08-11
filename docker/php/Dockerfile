FROM php:8.5-apache

ENV TZ=America/Santiago

RUN apt-get update \
    && apt-get install -y --no-install-recommends tzdata \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN printf "date.timezone=America/Santiago\n" > /usr/local/etc/php/conf.d/timezone.ini \
    && printf "upload_max_filesize=1024M\npost_max_size=1030M\nmax_file_uploads=20\n" > /usr/local/etc/php/conf.d/uploads.ini

COPY docker/apache/vhost.conf /etc/apache2/sites-available/mi-central.conf

RUN a2dissite 000-default.conf \
    && a2ensite mi-central.conf

WORKDIR /var/www/html

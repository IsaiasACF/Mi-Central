FROM php:8.5-cli

ENV TZ=America/Santiago

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates cron ffmpeg libcurl4-openssl-dev tzdata \
    && docker-php-ext-install curl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN printf "date.timezone=America/Santiago\n" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/temp \
        storage/video/uploads \
        storage/video/jobs \
        storage/video/exports \
        storage/video/temp \
    && chown -R www-data:www-data storage

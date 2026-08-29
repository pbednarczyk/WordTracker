FROM php:8.4-fpm-alpine

WORKDIR /var/www/app

RUN apk add --no-cache \
        git \
        icu-dev \
        libpq-dev \
        unzip \
    && docker-php-ext-install intl opcache pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/wordtracker-php-entrypoint
RUN chmod +x /usr/local/bin/wordtracker-php-entrypoint

COPY app/composer.json ./
COPY app/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY app ./

ENTRYPOINT ["wordtracker-php-entrypoint"]
CMD ["php-fpm"]

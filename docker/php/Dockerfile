FROM php:8.4-fpm-alpine

WORKDIR /var/www/app

RUN apk add --no-cache \
        git \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd intl opcache pdo_pgsql zip

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/wordtracker-php-entrypoint
RUN chmod +x /usr/local/bin/wordtracker-php-entrypoint

COPY app/composer.json ./
COPY app/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY app ./

ENTRYPOINT ["wordtracker-php-entrypoint"]
CMD ["php-fpm"]

FROM composer:latest AS composer

FROM php:8.3-alpine

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache \
    postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql \
    && pecl install swoole

RUN docker-php-ext-enable swoole

COPY . /app
WORKDIR /app

CMD ["php", "./index.php"]

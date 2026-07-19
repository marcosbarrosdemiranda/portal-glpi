FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libbz2-dev \
        libonig-dev \
        libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        intl \
        bz2 \
        exif \
        zip \
        opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php-custom.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html

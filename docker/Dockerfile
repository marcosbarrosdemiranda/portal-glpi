# Pinado em 8.2.35 (2026-10-24) — corrige CVE-2026-109618/109619 ("PHP2Shell",
# RCE críticas CVSS 9.2, exploração ativa confirmada). Tag flutuante php:8.2-apache
# evitada de propósito: rebuild futuro não deve puxar versão nova sem confirmação
# explícita (mesmo princípio da lição #4 do CLAUDE.md, mas pro lado do Dockerfile).
FROM php:8.2.35-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libbz2-dev \
        libonig-dev \
        libsqlite3-dev \
        libsnmp-dev \
        iputils-ping \
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
        snmp \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php-custom.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html

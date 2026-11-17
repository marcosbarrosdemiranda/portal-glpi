# Pinado em 8.2.35 (2026-10-24) — corrige CVE-2026-109618/109619 ("PHP2Shell",
# RCE críticas CVSS 9.2, exploração ativa confirmada). Tag flutuante php:8.2-apache
# evitada de propósito: rebuild futuro não deve puxar versão nova sem confirmação
# explícita (mesmo princípio da lição #4 do CLAUDE.md, mas pro lado do Dockerfile).
FROM php:8.2.35-apache

# openssh-client/sshpass: usados pelo check do DB Central (db_central_lib.php)
# via SSH somente leitura em 192.168.1.10.
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
        openssh-client \
        sshpass \
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

# mod_deflate já vem habilitado pela imagem base, mas o deflate.conf padrão do
# Debian não comprime application/json — eventos.php da Agenda devolve ~2,5MB
# de JSON sem filtro de data (ver achado de performance 2026-11-16), e isso
# ia sem compressão nenhuma em toda chamada. Conf própria (não edita o
# deflate.conf da distro, que seria sobrescrito num update do pacote apache2).
RUN printf '<IfModule mod_filter.c>\n\tAddOutputFilterByType DEFLATE application/json\n</IfModule>\n' > /etc/apache2/conf-available/json-deflate.conf \
    && a2enconf json-deflate

COPY php-custom.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html

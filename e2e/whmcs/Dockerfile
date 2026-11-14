# PHP + Apache + ionCube Loader image matching the WHMCS 9.1 system requirements
# (https://docs.whmcs.com/9-1/installation-guide/system-requirements/):
# PHP 8.2/8.3/8.4, ionCube Loader 15.0.0+, curl, gd, imap, intl, bcmath, gmp, soap, pdo_mysql (mysqlnd), ...
# WHMCS itself is not part of the image; it is mounted from e2e/.cache (see fetch-whmcs.sh).
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache-bookworm
ARG PHP_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libgmp-dev libxml2-dev libzip-dev \
        libc-client-dev libkrb5-dev unzip curl ca-certificates default-mysql-client cron rsync \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && PHP_OPENSSL=yes docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install -j"$(nproc)" gd imap intl bcmath gmp soap pdo_mysql mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# ionCube Loader (official build for this PHP version)
RUN curl -fsSL -o /tmp/ioncube.tar.gz https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz \
    && tar -xzf /tmp/ioncube.tar.gz -C /tmp \
    && cp "/tmp/ioncube/ioncube_loader_lin_${PHP_VERSION}.so" "$(php -r 'echo ini_get("extension_dir");')/ioncube_loader.so" \
    && echo "zend_extension=ioncube_loader.so" > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && rm -rf /tmp/ioncube /tmp/ioncube.tar.gz \
    && php -v | grep -i ioncube

RUN { echo 'memory_limit=256M'; echo 'max_execution_time=300'; echo 'date.timezone=Europe/Berlin'; echo 'display_errors=Off'; echo 'log_errors=On'; echo 'opcache.enable=0'; echo 'opcache.enable_cli=0'; } \
        > /usr/local/etc/php/conf.d/whmcs.ini \
    && a2enmod rewrite

WORKDIR /var/www/html

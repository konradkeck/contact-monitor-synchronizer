FROM php:8.3-cli-bookworm

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libc-client-dev \
        libkrb5-dev \
        libxml2-dev \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_pgsql dom \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install imap \
    && rm -rf /var/lib/apt/lists/*

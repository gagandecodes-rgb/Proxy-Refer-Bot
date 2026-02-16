FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    gcc g++ make autoconf pkg-config \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get purge -y --auto-remove gcc g++ make autoconf pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
COPY . /var/www/html/

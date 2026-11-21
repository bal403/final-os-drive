FROM php:8.2-apache

RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite mysqli pdo_mysql \
    && docker-php-ext-enable mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod -R 777 /var/www/html/data
FROM php:8.2-apache

LABEL maintainer="Ink API Team"

ARG WWWGROUP=1000
ARG NODE_VERSION=18
ARG POSTGRES_VERSION=15

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# Install base dependencies
RUN apt-get update && apt-get install -y \
    gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin \
    libpng-dev python3 dnsutils libonig-dev libxml2-dev libzip-dev \
    librsvg2-bin fswatch ffmpeg nano libcurl4-openssl-dev libpq-dev \
    libmagickwand-dev --no-install-recommends

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mysqli mbstring exif pcntl bcmath gd zip intl

# Install XDebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install Node.js
RUN curl -sL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && npm install -g yarn

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configure Apache
RUN a2enmod rewrite
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Create system user to run commands
RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail

# Configure PHP
COPY docker/8.2/php.ini /usr/local/etc/php/conf.d/99-sail.ini
COPY docker/8.2/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

# Set up entrypoint script
COPY docker/8.2/start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80

ENTRYPOINT ["start-container"]
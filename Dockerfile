FROM laravelsail/php82-composer:latest

USER root

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install image processing libraries for watermarking
RUN apt-get update && apt-get install -y \
    libwebp-dev \
    libjpeg-dev \
    libpng-dev \
    libfreetype6-dev \
    imagemagick \
    php8.2-imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Add user for laravel application
RUN groupadd -g 1000 www
RUN useradd -u 1000 -ms /bin/bash -g www www

# Change current user to www
USER www

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
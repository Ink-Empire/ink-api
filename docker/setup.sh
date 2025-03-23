#!/bin/sh
set -e

# Install composer
if [ ! -f /usr/local/bin/composer ]; then
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install project dependencies
if [ -f /var/www/html/composer.json ]; then
    cd /var/www/html
    echo "Installing project dependencies..."
    composer install --no-interaction
fi

# Set proper permissions
chown -R nobody:nobody /var/www/html/storage
chown -R nobody:nobody /var/www/html/bootstrap/cache

# Run Laravel migrations
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running migrations..."
    cd /var/www/html
    php artisan migrate --force
fi

# Run Laravel seeders
if [ "$RUN_SEEDERS" = "true" ]; then
    echo "Running seeders..."
    cd /var/www/html
    php artisan db:seed --force
fi

# Create Elasticsearch indices
if [ "$SETUP_ELASTICSEARCH" = "true" ]; then
    echo "Setting up Elasticsearch..."
    cd /var/www/html
    php artisan elastic:create-index-ifnotexists
    php artisan elastic:migrate
fi

# Copy entrypoint script to continue normal container startup
cp /var/www/html/docker/nginx/default.conf /etc/nginx/conf.d/default.conf

echo "Setup complete!"
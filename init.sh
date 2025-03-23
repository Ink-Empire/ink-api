#!/bin/bash

echo "Setting up the ink-api project..."

# Ensure the script is executable
chmod +x "$0"

# Step 1: Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
fi

# Step 2: Set up storage directories
echo "Setting up storage directories..."
mkdir -p storage/app/public
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/testing
mkdir -p storage/framework/views
mkdir -p storage/logs
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Step 3: Check for docker-compose command
if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE="docker compose"
fi

# Step 4: Pull Laravel Sail image
echo "Pulling Laravel Sail image..."
docker pull laravelsail/php82-composer:latest

# Step 5: Start Docker containers
echo "Starting Docker containers..."
$DOCKER_COMPOSE down --remove-orphans
$DOCKER_COMPOSE up -d

# Step 6: Wait for containers to be ready
echo "Waiting for containers to be ready..."
sleep 15

# Step 7: Install Composer dependencies
echo "Installing Composer dependencies..."
$DOCKER_COMPOSE exec laravel.app composer install

# Step 8: Generate application key
echo "Generating application key..."
$DOCKER_COMPOSE exec laravel.app php artisan key:generate --no-interaction

# Step 9: Run migrations and seeders
echo "Running migrations and seeders..."
$DOCKER_COMPOSE exec laravel.app php artisan migrate --seed --no-interaction

# Step 10: Set up Elasticsearch
echo "Setting up Elasticsearch..."
$DOCKER_COMPOSE exec laravel.app php artisan elastic:create-index-ifnotexists --no-interaction
$DOCKER_COMPOSE exec laravel.app php artisan elastic:migrate --no-interaction

echo "Setup complete! The application is now running."
echo "You can access it at: http://localhost"
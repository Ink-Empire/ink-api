# Ink API

This is the API for the Inkedin platform, built with Laravel 10 and Elasticsearch.

## Requirements

- Docker & Docker Compose
- Git

## Quick Setup

The easiest way to set up the project is to run the initialization script:

```bash
chmod +x init.sh
./init.sh
```

This script will:
1. Create a .env file if it doesn't exist
2. Set up the storage directory structure
3. Pull the pre-built Laravel Sail image
4. Start Docker containers
5. Install Composer dependencies inside the container
6. Generate application key
7. Run migrations and seeders
8. Create Elasticsearch indices and import data

## Manual Setup

If you prefer to set up manually, follow these steps:

1. Clone this repository
2. Copy the `.env.example` file to `.env`
   ```bash
   cp .env.example .env
   ```
3. Create the necessary directory structure
   ```bash
   mkdir -p storage/app/public
   mkdir -p storage/framework/cache
   mkdir -p storage/framework/sessions
   mkdir -p storage/framework/testing
   mkdir -p storage/framework/views
   mkdir -p storage/logs
   chmod -R 775 storage
   chmod -R 775 bootstrap/cache
   ```
4. Pull the Laravel Sail image
   ```bash
   docker pull laravelsail/php82-composer:latest
   ```
5. Start the Docker containers
   ```bash
   docker compose up -d
   ```
6. Install Composer dependencies
   ```bash
   docker compose exec laravel.app composer install
   ```
7. Generate application key
   ```bash
   docker compose exec laravel.app php artisan key:generate
   ```
8. Run database migrations and seed the database
   ```bash
   docker compose exec laravel.app php artisan migrate --seed
   ```
9. Create Elasticsearch indices and import data
   ```bash
   docker compose exec laravel.app php artisan elastic:create-index-ifnotexists
   docker compose exec laravel.app php artisan elastic:migrate
   ```

## Elasticsearch Commands

Here are the available Elasticsearch commands:

```bash
# Create Elasticsearch indices
docker compose exec laravel.app php artisan elastic:create-index-ifnotexists

# Build and index data for Elasticsearch
docker compose exec laravel.app php artisan elastic:migrate

# Rebuild the data for a specific model
docker compose exec laravel.app php artisan elastic:rebuild "App\\Models\\Tattoo"
docker compose exec laravel.app php artisan elastic:rebuild "App\\Models\\Artist"
```

You can check your Elasticsearch indices at: http://localhost:9200/_cat/indices

## Available Services

- **Laravel App**: [http://localhost](http://localhost)
- **MySQL**: Port 3306
- **Redis**: Port 6379
- **Elasticsearch**: Port 9200

## Useful Commands

```bash
# Start containers
docker compose up -d

# Stop containers
docker compose down

# Run artisan commands
docker compose exec laravel.app php artisan <command>

# Run composer commands
docker compose exec laravel.app composer <command>

# Run tests
docker compose exec laravel.app php artisan test

# View logs
docker compose logs -f
```

## Frontend Integration

This API is designed to work with the Inkedin frontend application. Make sure the frontend is configured to point to this API.
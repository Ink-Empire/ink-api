# Laravel 11 Upgrade for Inkedin API

This document outlines the changes made to upgrade the Inkedin API to Laravel 11 with Elasticsearch support.

## Changes Made

1. **Updated Docker Configuration**
   - Created `docker-compose.yml` with services for Laravel, MySQL, Redis, and Elasticsearch
   - Added Docker configuration files for PHP 8.2
   - Set up Dockerfile.sail for Laravel Sail

2. **Laravel 11 Upgrade**
   - Updated `composer.json` to use Laravel 11 packages
   - Updated dependencies for compatibility
   - Added official Elasticsearch PHP package

3. **Elasticsearch Configuration**
   - Updated Elasticsearch configuration in `config/elastic.php`
   - Added Elasticsearch service to Docker Compose
   - Updated `.env.example` with Elasticsearch configuration options

4. **Documentation**
   - Updated README with new setup instructions
   - Added documentation for Elasticsearch usage

## How to Use

Follow the instructions in the README.md file to set up and run the application. The key steps are:

1. Clone the repository
2. Set up `.env` file
3. Start Docker containers with `./sail up -d`
4. Install dependencies with `./sail composer install`
5. Run migrations with `./sail artisan migrate`
6. Set up Elasticsearch indices

## Notes for Frontend Integration

The API structure remains compatible with the existing frontend application. 
Make sure to update any environment variables in the frontend to point to this API.

## Elasticsearch Commands

The following commands are available for Elasticsearch management:

- `./sail artisan elastic:create-index "App\\Models\\Tattoo"` - Create the Tattoo index
- `./sail artisan elastic:create-index "App\\Models\\Artist"` - Create the Artist index
- `./sail artisan elastic:delete-index "App\\Models\\Tattoo"` - Delete the Tattoo index
- `./sail artisan elastic:delete-index "App\\Models\\Artist"` - Delete the Artist index
- `./sail artisan scout:import "App\\Models\\Tattoo"` - Import tattoos to Elasticsearch
- `./sail artisan scout:import "App\\Models\\Artist"` - Import artists to Elasticsearch
# Elasticsearch Implementation for Inked In

This document provides an in-depth analysis of the Elasticsearch implementation used by the Inked In platform. The application leverages Elasticsearch to power its search functionality, providing fast and relevant results for tattoos, artists, and studios.

## Getting Started with Elasticsearch Indices

This section provides step-by-step instructions for setting up and managing Elasticsearch indices for the Inked In platform.

### Prerequisites

1. **Elasticsearch Server**: Ensure Elasticsearch is running and accessible
2. **Environment Configuration**: Set up your `.env` file with proper Elasticsearch connection details:
   ```
   ELASTICSEARCH_HOST=localhost:9200
   ELASTICSEARCH_USER=elastic
   ELASTICSEARCH_PASS=your_password
   ```

### Initial Setup

#### 1. Initialize Elasticsearch Indices

To create both tattoos and artists indices and populate them with existing data:

```bash
# Create both indices and import all data
php artisan elastic:migrate

# Or create indices separately without importing data
php artisan elasticsearch:init
```

**What this does:**
- Creates the `tattoos` index with proper mappings
- Creates the `artists` index with proper mappings  
- Imports all existing tattoo and artist data from the database
- Sets up read/write aliases for zero-downtime operations

#### 2. Create Individual Indices

To create specific indices only:

```bash
# Create only the tattoos index
php artisan elasticsearch:init tattoos --model=Tattoo

# Create only the artists index  
php artisan elasticsearch:init artists --model=Artist

# Create a specific index without importing data
php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
php artisan elastic:create-index-ifnotexists --model="App\Models\Artist"
```

### Index Management

#### Rebuilding Indices

When you need to update mappings or refresh all data:

```bash
# Rebuild the entire tattoos index
php artisan elastic:rebuild "App\Models\Tattoo"

# Rebuild the entire artists index
php artisan elastic:rebuild "App\Models\Artist"
```

**Note:** Rebuild operations are queued as background jobs to prevent timeouts with large datasets.

#### Deleting Indices

To remove indices (useful for development/testing):

```bash
# Delete tattoos index
php artisan elastic:delete-index --model="App\Models\Tattoo"

# Delete artists index
php artisan elastic:delete-index --model="App\Models\Artist"
```

### Common Workflows

#### Fresh Development Setup

For a clean development environment:

```bash
# 1. Delete existing indices (if any)
php artisan elastic:delete-index --model="App\Models\Tattoo"
php artisan elastic:delete-index --model="App\Models\Artist"

# 2. Create fresh indices and import data
php artisan elastic:migrate
```

#### Production Deployment

For production deployments with zero downtime:

```bash
# 1. Create indices with updated mappings
php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
php artisan elastic:create-index-ifnotexists --model="App\Models\Artist"

# 2. Rebuild indices (runs in background)
php artisan elastic:rebuild "App\Models\Tattoo"
php artisan elastic:rebuild "App\Models\Artist"
```

#### Data Synchronization

To sync specific data after database changes:

```bash
# Import only new data without rebuilding
php artisan elasticsearch:init --model=Tattoo
php artisan elasticsearch:init --model=Artist
```

### Troubleshooting

#### Common Issues

1. **Index Already Exists Error**
   ```bash
   # Use the "if not exists" variant
   php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
   ```

2. **Connection Refused**
   - Check if Elasticsearch is running: `curl http://localhost:9200`
   - Verify `.env` configuration
   - Check firewall settings

3. **Mapping Conflicts**
   ```bash
   # Delete and recreate with new mappings
   php artisan elastic:delete-index --model="App\Models\Tattoo"
   php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
   php artisan elastic:rebuild "App\Models\Tattoo"
   ```

4. **Large Dataset Timeouts**
   - Rebuild operations automatically use background jobs
   - Monitor the queue: `php artisan queue:work`

#### Monitoring Commands

```bash
# Check if indices exist
curl -X GET "localhost:9200/_cat/indices?v"

# Check index mappings
curl -X GET "localhost:9200/tattoos/_mapping?pretty"
curl -X GET "localhost:9200/artists/_mapping?pretty"

# Check document count
curl -X GET "localhost:9200/tattoos/_count"
curl -X GET "localhost:9200/artists/_count"
```

### Best Practices

1. **Always use background jobs for large operations**
   - Rebuild commands automatically queue jobs
   - Monitor with `php artisan queue:work`

2. **Test mapping changes in development first**
   ```bash
   # Development workflow
   php artisan elastic:delete-index --model="App\Models\Tattoo"
   php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
   # Test your changes, then apply to production
   ```

3. **Use aliases for zero-downtime updates**
   - The system automatically manages read/write aliases
   - Rebuild operations create new indices and swap aliases

4. **Monitor index health regularly**
   ```bash
   # Check cluster health
   curl -X GET "localhost:9200/_cluster/health?pretty"
   ```

## Architecture Overview

The Elasticsearch implementation follows a layered architecture:

1. **Search Models** - Define what data is indexed and how it's structured
2. **Index Configurators** - Define mapping, analyzers, and index settings
3. **Services** - Encapsulate Elasticsearch operations and query building
4. **Custom Scout Engine** - Integrates with Laravel Scout for ORM-to-index operations
5. **Commands & Jobs** - Handle index maintenance and data synchronization

## Core Components

### ElasticsearchService

`ElasticsearchService` (`app/Services/ElasticsearchService.php`) provides a basic wrapper around the official Elasticsearch PHP client with methods for:

- Index management (creating, deleting, checking existence)
- Document operations (adding, updating, deleting)
- Search execution
- Bulk indexing

This service is designed to be a straightforward interface to the Elasticsearch REST API, handling client configuration, request formatting, and error handling.

### ElasticService 

`ElasticService` (`app/Services/ElasticService.php`) is a higher-level service that builds on ElasticsearchService, offering more advanced features:

- Query building and execution
- Index aliasing and migration
- Document reindexing
- Index structure management
- Advanced search capabilities including GeoSearch

Key features:

- **Index Aliasing** - Manages read/write aliases to enable zero-downtime reindexing
- **Query Translation** - Converts application-level search parameters to Elasticsearch queries
- **Error Handling** - Robust exception handling and logging
- **Bulk Operations** - Efficient batch processing of documents

### ElasticsearchEngine

`ElasticsearchEngine` (`app/Scout/ElasticsearchEngine.php`) extends Laravel Scout's engine interface to provide Elasticsearch-specific implementations:

- Maps Laravel models to Elasticsearch documents
- Converts Scout builder queries to Elasticsearch syntax
- Handles search response mapping back to Eloquent models
- Manages index creation, updating, and deletion

This component bridges the gap between Laravel's ORM and Elasticsearch, making models searchable with minimal configuration.

## Index Configuration

### Index Configurators

Two primary index configurators define the structure of the search indices:

1. **ArtistIndexConfigurator** (`app/Models/ArtistIndexConfigurator.php`)
   - Maps artist data including profiles, styles, and portfolio information
   - Includes nested structures for related entities (studios, tattoos, styles)
   - Configures geo-point fields for location-based searches

2. **TattooIndexConfigurator** (`app/Models/TattooIndexConfigurator.php`)
   - Maps tattoo data including metadata, styles, and images
   - Includes nested objects for related entities (artist, studio, styles)
   - Configures text analyzers for natural language search
   - Includes uploader fields (`uploader_name`, `uploader_username`, `uploader_slug`) for client-uploaded tattoos

Both configurators define:

- Custom analyzers and tokenizers for improved text search
- Field mappings with appropriate data types
- Nested object relationships
- Geo-point fields for location searches

### Key Field Types

- **Geo Points** - Store latitude/longitude coordinates for location-based searches
- **Nested Objects** - Store complex relationships (artists → styles, tattoos → images)
- **Text Fields** - Full-text searchable with analyzers for natural language processing
- **Keyword Fields** - Exact match fields for filtering and aggregations

## Search Functionality

The search capabilities are primarily implemented in the `SearchService` class, which offers:

- **Text Search** - Find tattoos and artists by keywords in descriptions, names, uploader usernames, etc.
- **Style-Based Search** - Filter results by tattoo styles
- **Location-Based Search** - Find nearby artists and studios using geo distance queries
- **Relational Filtering** - Filter by related entities (e.g., tattoos by a specific artist)
- **Saved Preferences** - Return results based on a user's saved artists or styles

Key search methods:

- `search_tattoo()` - Search for tattoos with comprehensive filters (searches description, artist_name, studio_name, uploader_name, uploader_username)
- `search_artist()` - Search for artists with location and style filters
- `initialUserResults()` - Generate personalized search results for a user

### Client User Search Fallback

Artists and tattoos are searchable via Elasticsearch. Client users are not indexed in ES. When an artist search returns no results, the frontend automatically falls back to a lightweight database query (`POST /api/users/search`) that searches client users by name and username. This provides basic discoverability for non-artist users without the overhead of maintaining a separate ES index.

## Maintenance and Operations

### Commands

Several Artisan commands manage the Elasticsearch indices:

1. **ElasticMigrateCommand** (`app/Console/Commands/ElasticMigrateCommand.php`)
   - Creates indices and imports initial data
   - Ensures both Tattoo and Artist indices exist and contain current data

2. **ElasticRebuildCommand** (`app/Console/Commands/ElasticRebuildCommand.php`)
   - Rebuilds the index for a specific model
   - Useful for updating mappings or refreshing data

3. **CreateIndexIfNotExists** (`app/Console/Commands/CreateIndexIfNotExists.php`)
   - Creates indices if they don't already exist
   - Prevents errors when trying to use non-existent indices

4. **DeleteIndexIfExistsCommand** (`app/Console/Commands/DeleteIndexIfExistsCommand.php`)
   - Removes indices if they exist
   - Used in development and testing environments

### Background Jobs

Background jobs handle resource-intensive Elasticsearch operations:

1. **IndexTattooJob** (`app/Jobs/IndexTattooJob.php`)
   - Standard job for all tattoo indexing operations (single uploads, tag changes, feature toggles, etc.)
   - Eagerly loads all relations (`tags`, `styles`, `images`, `artist`, `studio`, `primary_style`, `primary_image`) before indexing
   - Re-indexes the associated artist by default (can be disabled with `reindexArtist: false`)
   - 3 retries with backoff (5s, 15s, 30s)
   - Used by `TattooController`, `TagController`, and `GenerateAiTagsJob`

2. **ElasticRebuildJob** (`app/Jobs/ElasticRebuildJob.php`)
   - Processes full index rebuilds asynchronously
   - Takes a model and IDs to rebuild specific documents

3. **ElasticMigrateJob** (`app/Jobs/ElasticMigrateJob.php`)
   - Handles index migration processes in the background
   - Manages alias updates and reindexing

## Query Building

The Elasticsearch query building process leverages multiple components:

1. **SearchService** builds initial query structures based on request parameters
2. **ElasticService** translates those structures to Elasticsearch DSL
3. **Scout Builder** further refines and executes the queries

Special query features include:

- **GeoDistance Queries** - Find results within a specific radius of coordinates. For artist proximity lookups, prefer the reusable `App\Services\ArtistService::getNearby($lat, $lng, $distance)` helper instead of writing a new `whereDistance` call — it queries the artists index's `location_lat_long` `geo_point` field and rehydrates Eloquent `Artist` models from the matching IDs.
- **Nested Queries** - Search within arrays of related objects (styles, images)
- **Minimum Should Match** - Control partial matching for OR conditions
- **Prefix Matching** - Support for autocomplete-style search

## Zero-Downtime Reindexing

The system supports zero-downtime reindexing through an alias-based approach:

1. Create a new index with updated mappings
2. Copy data from the old index to the new index
3. Update aliases to point to the new index
4. Remove the old index

This process is managed through the `ElasticService` class with methods like:
- `getMaxAlias()` - Determines the next index version
- `createAliasForTargetIndex()` - Updates aliases to point to new indices
- `_reindex()` - Copies data between indices

## Best Practices Implemented

The Elasticsearch implementation follows several best practices:

1. **Error Handling** - Comprehensive try/catch blocks with detailed error logging
2. **Index Versioning** - Uses versioned indices with aliases for zero-downtime updates
3. **Bulk Operations** - Uses bulk APIs for efficient document operations
4. **Asynchronous Processing** - Moves intensive operations to background jobs
5. **Configurable Settings** - Uses environment variables for Elasticsearch configuration
6. **Custom Analyzers** - Implements specialized text analysis for better search results
7. **Proper Mapping** - Uses appropriate field types for different data

## Configuration

Elasticsearch settings are stored in `config/elastic.php` and include:

- Host configurations
- Authentication settings
- Default index names
- Connection timeouts
- Index configuration options

These settings can be customized through environment variables in the `.env` file.

## Integration with Laravel Scout

The application extends Laravel Scout to provide seamless integration between Eloquent models and Elasticsearch:

1. Models use the `Searchable` trait to become indexable
2. `toSearchableArray()` methods define what data gets indexed
3. The custom `ElasticsearchEngine` handles the Scout-to-Elasticsearch translation
4. The `searchableAs()` method determines which index a model uses

## Conclusion

The Elasticsearch implementation for Inked In provides a robust foundation for the application's search functionality. It enables complex searches across tattoos, artists, and studios, with particular emphasis on location-based discovery and style-based filtering.

The architecture balances performance, flexibility, and maintainability through:

- Separation of concerns between different service layers
- Robust error handling and logging
- Background processing for intensive operations
- Zero-downtime index updates
- Custom analyzers for improved search relevance

This implementation enables the core search features that power the user experience, allowing clients to discover artists and tattoos, and helping artists showcase their work to potential clients.
# Custom Artisan Commands Reference

This document covers the custom Laravel Artisan commands specific to the ink-api project, focusing on Elasticsearch/OpenSearch operations, AI/ML functionality, and custom development tools.

## Table of Contents

1. [Elasticsearch/OpenSearch Commands](#elasticsearchopensearch-commands)
2. [AI/ML Commands](#aiml-commands)
3. [Custom Development Commands](#custom-development-commands)

---

## Elasticsearch/OpenSearch Commands

These commands are crucial for managing the search functionality in the Inked In platform.

### Connection and Testing

#### Test Elasticsearch/OpenSearch Connection
```bash
php artisan elastic:test-connection
```
**What it does:**
- Tests connectivity to your configured Elasticsearch/OpenSearch cluster
- Validates authentication credentials
- Shows cluster health and connection latency
- Automatically detects AWS OpenSearch vs standard Elasticsearch

**When to use:**
- After configuring new Elasticsearch endpoints
- Troubleshooting connection issues
- Verifying credentials after updates
- Monitoring cluster health

### Index Creation

#### Create Index (Safe)
```bash
php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
php artisan elastic:create-index-ifnotexists --model="App\Models\Artist"
```
**What it does:**
- Creates Elasticsearch indices only if they don't already exist
- Uses proper mapping configurations from IndexConfigurators
- Sets up aliases for zero-downtime operations
- Safe for production use

**When to use:**
- Initial deployment setup
- Production deployments
- When you want to avoid "index already exists" errors

#### Create Index (Force)
```bash
php artisan elastic:create-index --model="App\Models\Tattoo"
php artisan elastic:create-index --model="App\Models\Artist"
```
**What it does:**
- Creates Elasticsearch indices unconditionally
- Will fail if index already exists
- Uses mapping configurations from IndexConfigurators

**When to use:**
- Development environments
- When you're certain the index doesn't exist
- Initial setup scenarios

### Index Deletion

#### Delete Index (Safe)
```bash
php artisan elastic:delete-index --model="App\Models\Tattoo"
php artisan elastic:delete-index --model="App\Models\Artist"
```
**What it does:**
- Removes Elasticsearch indices if they exist
- Safe operation that won't error if index doesn't exist
- Cleans up associated aliases

**When to use:**
- Development environment cleanup
- Before major mapping changes
- Testing and debugging

#### Drop Index (Force)
```bash
php artisan elastic:drop-index --model="App\Models\Tattoo"
php artisan elastic:drop-index --model="App\Models\Artist"
```
**What it does:**
- Forces deletion of Elasticsearch indices
- Will error if index doesn't exist
- More aggressive than delete-index

**When to use:**
- When you need explicit confirmation of index existence
- Scripted operations where you expect the index to exist

### Index Rebuilding (Critical Operations)

#### Full Index Rebuild
```bash
php artisan elastic:rebuild "App\Models\Tattoo"
php artisan elastic:rebuild "App\Models\Artist"
```
**What it does:**
- Completely rebuilds the specified index with fresh data
- Creates new versioned index alongside the old one
- Imports all model data from database
- Updates aliases for zero-downtime operation
- **Runs as background job to prevent timeouts**
- Removes old index after successful completion

**When to use:**
- After mapping changes
- Data corruption issues
- Major database schema updates
- Periodic index maintenance

**Important Notes:**
- This is a **background operation** - monitor with `php artisan queue:work`
- Large datasets may take significant time
- Zero downtime when using aliases properly

#### Individual Item Rebuild
```bash
php artisan elastic:rebuild-item tattoo 123
php artisan elastic:rebuild-item artist 456
```
**What it does:**
- Rebuilds a single record in the index
- Updates specific document without full reindex
- Useful for targeted fixes

**When to use:**
- Single record data corruption
- Testing index updates
- Quick fixes for specific documents

### Migration and Data Import

#### Full Migration (Recommended for Initial Setup)
```bash
php artisan elastic:migrate
```
**What it does:**
- Creates both tattoos and artists indices if they don't exist
- Imports all existing data from database
- Sets up proper aliases and configurations
- **The most comprehensive setup command**

**When to use:**
- Initial application deployment
- Fresh development environment setup
- Complete index recreation

#### Legacy Index Initialization
```bash
php artisan elasticsearch:init
php artisan elasticsearch:init --model=Tattoo
php artisan elasticsearch:init --model=Artist
```
**What it does:**
- Initializes indices and imports data
- Legacy command, prefer `elastic:migrate`
- Can target specific models

### Index Maintenance

#### Update Index Settings
```bash
php artisan elastic:update-index --model="App\Models\Tattoo"
php artisan elastic:update-index --model="App\Models\Artist"
```
**What it does:**
- Updates index settings without recreating
- Applies configuration changes from IndexConfigurators
- Non-destructive operation

#### Update Mapping
```bash
php artisan elastic:update-mapping --model="App\Models\Tattoo"
php artisan elastic:update-mapping --model="App\Models\Artist"
```
**What it does:**
- Updates field mappings in existing indices
- Some mapping changes require full rebuild
- Check Elasticsearch documentation for mapping limitations

### Common Elasticsearch Workflows

#### Fresh Development Setup
```bash
# 1. Clean slate
php artisan elastic:delete-index --model="App\Models\Tattoo"
php artisan elastic:delete-index --model="App\Models\Artist"

# 2. Create and populate
php artisan elastic:migrate
```

#### Production Deployment with Mapping Changes
```bash
# 1. Create new indices (won't affect existing)
php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
php artisan elastic:create-index-ifnotexists --model="App\Models\Artist"

# 2. Rebuild with new mappings (zero downtime)
php artisan elastic:rebuild "App\Models\Tattoo"
php artisan elastic:rebuild "App\Models\Artist"

# 3. Monitor background jobs
php artisan queue:work
```

#### Troubleshooting Connection Issues
```bash
# 1. Test connection
php artisan elastic:test-connection

# 2. Check if indices exist
curl -X GET "localhost:9200/_cat/indices?v"
# or for AWS OpenSearch:
curl -X GET "https://your-domain.region.es.amazonaws.com/_cat/indices?v"

# 3. Recreate if needed
php artisan elastic:migrate
```

---

## AI/ML Commands

### Tattoo AI Tagging
```bash
# Generate AI tags for specific tattoo
php artisan tattoos:generate-tags {tattoo_id}

# Backfill AI tags for all existing tattoos with images
php artisan tattoos:backfill-tags
```
**What these do:**
- Use OpenAI to analyze tattoo images and generate relevant tags
- Backfill command processes all tattoos that have images but no AI tags
- Essential for improving search relevance and categorization

---

## Custom Development Commands

### Elasticsearch-Specific Generators
```bash
# Create new index configurator
php artisan make:index-configurator {name}

# Create new searchable model
php artisan make:searchable-model {name}
```

**What these do:**
- `make:index-configurator`: Creates a new Elasticsearch index configurator class that defines index mappings, settings, and analyzers
- `make:searchable-model`: Creates a new Eloquent model that extends Laravel Scout's searchable functionality with Elasticsearch integration

**When to use:**
- Adding new searchable entities to the platform
- Creating custom index configurations for specialized search requirements

---

## Critical Custom Command Sequences

### Initial Elasticsearch Setup
```bash
# Set up Elasticsearch indices and import data
php artisan elastic:migrate
```

### Production Deployment (Custom Commands Only)
```bash
# 1. Update Elasticsearch indices
php artisan elastic:create-index-ifnotexists --model="App\Models\Tattoo"
php artisan elastic:create-index-ifnotexists --model="App\Models\Artist"

# 2. Rebuild indices if needed (runs in background)
php artisan elastic:rebuild "App\Models\Tattoo"
php artisan elastic:rebuild "App\Models\Artist"

# 3. Generate AI tags for new tattoos
php artisan tattoos:backfill-tags
```

### Emergency Elasticsearch Recovery
```bash
# 1. Test connection
php artisan elastic:test-connection

# 2. If connection works but indices are corrupted, rebuild:
php artisan elastic:delete-index --model="App\Models\Tattoo"
php artisan elastic:delete-index --model="App\Models\Artist"
php artisan elastic:migrate
```

---

## Best Practices for Custom Commands

### Elasticsearch Operations
1. **Always test connection first**: `php artisan elastic:test-connection`
2. **Use background processing**: Elasticsearch rebuild commands run as background jobs - monitor with `php artisan queue:work`
3. **Use safe commands in production**: Prefer `create-index-ifnotexists` over `create-index`
4. **Monitor during rebuilds**: Large indices can take significant time to rebuild

### AI/ML Operations  
1. **Batch processing**: Use `tattoos:backfill-tags` for bulk operations rather than individual commands
2. **Monitor API usage**: OpenAI API calls have rate limits and costs
3. **Test on small datasets**: Try single tattoo tagging before running backfill operations

### Development Workflow
1. **Test Elasticsearch connectivity**: Always verify connection before running index operations
2. **Use development environment**: Test index rebuilds and AI operations on staging data first
3. **Monitor queue workers**: Custom commands rely heavily on background job processing

---

This guide covers only the custom commands specific to the ink-api project. For standard Laravel commands, refer to the official Laravel documentation.

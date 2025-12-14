# Elastic Resources

This folder contains JSON API resources used for Elasticsearch operations.

## Resource Types

### Index Resources (`*IndexResource.php`)

Used for **Elasticsearch indexing** - these define the document structure stored in the ES index.

| Resource | Used By | Purpose |
|----------|---------|---------|
| `ArtistIndexResource` | `Artist::toSearchableArray()` | Defines artist document structure in ES |
| `TattooIndexResource` | `Tattoo::toSearchableArray()` | Defines tattoo document structure in ES |

**Characteristics:**
- Include denormalized/flattened fields for search (e.g., `artist_name`, `studio_name`)
- Include full nested objects via standard resources
- Used only by model `toSearchableArray()` methods

### Standard Resources (`ArtistResource.php`, `TattooResource.php`)

Used for **API responses** and **nested relationships** within Index resources.

| Resource | Used By | Purpose |
|----------|---------|---------|
| `ArtistResource` | Controllers, nested in `TattooIndexResource` | API responses, nested artist in tattoo docs |
| `TattooResource` | Controllers, nested in `ArtistIndexResource` | API responses, nested tattoos in artist docs |

**Characteristics:**
- Simpler structure for API consumption
- Used when embedding one entity inside another to avoid circular references

## Why Two Resource Types?

The separation prevents **circular references / infinite recursion**.

When indexing an Artist to Elasticsearch:
```
ArtistIndexResource
  └── tattoos: TattooResource[] (NOT TattooIndexResource)
        └── Does NOT re-embed all artist data
```

When indexing a Tattoo to Elasticsearch:
```
TattooIndexResource
  └── artist: ArtistResource (NOT ArtistIndexResource)
        └── Does NOT re-embed all tattoos
```

If we used `ArtistIndexResource` nested inside `TattooIndexResource`, and vice versa,
we'd get infinite recursion as each tries to fully serialize the other.

## Adding New Fields

When adding a new field to be indexed in Elasticsearch:

1. Add to the appropriate `*IndexResource.php` file
2. Add the field mapping in the corresponding `*IndexConfigurator.php` (in `app/Models/`)
3. Run the ES mapping update command
4. Rebuild the index

## File Locations

- Index Resources: `app/Http/Resources/Elastic/*IndexResource.php`
- Standard Resources: `app/Http/Resources/Elastic/*Resource.php`
- Index Configurators: `app/Models/*IndexConfigurator.php`

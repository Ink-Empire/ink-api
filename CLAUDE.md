# ink-api Development Guide

## Production URLs
- **Frontend**: https://getinked.in (NOT inkedin.com)
- **API**: https://api.getinked.in

## Code Style Guidelines
- **Framework**: Laravel PHP (PSR standards)
- **PHP Version**: 8.3+
- **Formatting**: Laravel Pint (preset: laravel)
- **Namespacing**: PSR-4 with App\\ namespace
- **Models**: Located in app/Models with appropriate relationships
- **Folder Structure**: Follow Laravel conventions (Controllers, Services, Jobs, etc.)
- **Error Handling**: Use Laravel exceptions and proper try/catch blocks
- **Testing**: PHPUnit for backend, Laravel Dusk for frontend
- **Documentation**: DocBlocks on classes and complex methods
- When asked to document a flow or process, generate
- Mermaid diagrams and save to `docs/flows/`.

### Standard prompts for generating flow docs:

**Auth flow:**
Analyze auth controllers, middleware, and models. Generate a mermaid flowchart for registration, login, email verification, and session handling.

**Booking flow:**
Trace booking requests from client submission through artist notification. Create a sequence diagram showing frontend -> API -> database -> notifications.

**Image upload flow:**
Document the tattoo upload pipeline including storage, AI tagging, user confirmation, and Elasticsearch indexing.

**Search flow:**
Map search from user input through Elasticsearch, filters, notBlockedBy scope, and result ranking.

### Mermaid conventions:
- Use `([text])` for start/end terminals
- Use `[text]` for processes
- Use `{text}` for decisions
- Use `-->|label|` for labeled arrows
- Save output to `docs/flows/[flow-name].md`
- **Git Flow**: Create branches from develop, request code review before merging
- Don't automatically perform any git operations; I'll handle git and version control

## API Response Guidelines
- **Always use Laravel Resources** - never return hardcoded response arrays
- Use `$this->returnResponse('key', new SomeResource($model))` pattern
- Resources ensure consistent response structure and proper data transformation

### Available Resources
- `DashboardArtistResource` - Artist cards (id, name, username, image, studio, styles)
- `BriefArtistResource` - Minimal artist info (id, name, username, email, phone)
- `UserResource` - Full user profile data
- `BriefImageResource` - Image with URI
- `BriefStudioResource` - Studio summary

### Example
```php
// Good - using a Resource
$artist = Artist::with('image')->find($id);
return $this->returnResponse('artist', new DashboardArtistResource($artist));

// Bad - hardcoded response
return response()->json([
    'artist' => [
        'id' => $artist->id,
        'name' => $artist->name,
    ]
]);
```

## Elasticsearch Guidelines
- **Always use the Scout Elasticsearch library syntax** - never raw Elasticsearch query DSL
- Use `Model::search()->where()->get()` pattern for queries
- Response format: `$results['response']` contains the array of results

### Query Examples
```php
// Simple lookup by field
$results = Artist::search()
    ->where('username', $value)
    ->take(1)
    ->get();
$artist = collect($results['response'] ?? $results)->first();

// Multiple values
$this->search->where('style_ids', 'in', $styleIds);

// Boolean filter
$this->search->where('settings.books_open', 'in', [true]);

// Pagination
$this->search->from($offset);
$this->search->take($perPage);
```

### Important Notes
- Elasticsearch returns arrays, not Eloquent models - access data with `$result['field']` not `$result->field`
- Prefer Elasticsearch over database queries for read operations to conserve database resources
- Use database queries only for writes or when data must be real-time (not yet indexed)

## Testing Guidelines
- All tests should follow Laravel testing conventions
- Mocking should be reserved for complex situations
- Laravel models should be used directly in tests whenever possible
- All test methods should be prefixed with "test"; this is required in the latest version of PHPUnit

### Test Database
Tests use a separate MySQL database (`inkedin_test`) to avoid affecting development data.

**First-time setup:**
```bash
# In Docker container, create the test database:
mysql -u sail -ppassword -e "CREATE DATABASE IF NOT EXISTS inkedin_test;"
```

**Run tests:**
```bash
php artisan test
```

The `phpunit.xml` uses `force="true"` to ensure the `inkedin_test` database is always used.

All code changes must pass CI tests and receive an approval before merging to develop.
Always check the /docs directory to understand the flow and update it when we make changes to a process
Keep comments to a minimum and never put emojis into this project
Do not change the order of database migrations or attempt to rename them.

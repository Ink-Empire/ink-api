# API Contract Testing

## Overview

Contract tests verify that API endpoints return expected response structures and export JSON fixtures for frontend Playwright tests. This ensures backend changes don't break frontend expectations.

## Architecture

```
                         ink-api
                            |
    +-------------------+---+---+-------------------+
    |                   |       |                   |
    v                   v       v                   v
Controllers -----> Contract Tests <----- Resources
    |                   |
    |                   v
    |            Export Fixtures
    |                   |
    v                   v
API Response -----> S3 Bucket
                        |
                        v
                   Frontend E2E Tests
```

### How It Works

1. Contract tests call API endpoints and verify response structure
2. Tests export JSON fixtures when `EXPORT_FIXTURES=true`
3. GitHub Actions uploads fixtures to S3 on relevant file changes
4. Frontend pulls fixtures from S3 for Playwright mocking

## Test Structure

```
tests/
├── Feature/
│   └── Contracts/
│       ├── ArtistContractTest.php          # Artist endpoint contracts
│       ├── AuthContractTest.php            # Registration/login contracts
│       ├── ClientDashboardContractTest.php # Dashboard contracts
│       ├── TattooContractTest.php          # Tattoo endpoint contracts
│       └── UserContractTest.php            # User profile contracts
├── fixtures/                                # Exported JSON fixtures
│   ├── artist/
│   ├── auth/                                # Registration/login fixtures
│   ├── client/
│   ├── tattoo/
│   └── user/
├── CreatesApplication.php                   # App bootstrap with DB safeguard
├── Pest.php                                 # Pest configuration
├── RefreshTestDatabase.php                  # Custom trait for testing migrations
└── TestCase.php                             # Base test case
```

## Running Tests

### IMPORTANT: Use Test Database

Contract tests **must** run against the `inkedin_test` database to avoid wiping your local development data. A safeguard in `tests/CreatesApplication.php` will abort if connected to the production database.

```bash
# First time setup: create test database
mysql -u root -ppassword -e "CREATE DATABASE IF NOT EXISTS inkedin_test; GRANT ALL PRIVILEGES ON inkedin_test.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

# Run migrations on test database
DB_DATABASE=inkedin_test php artisan migrate
```

### Run Contract Tests

```bash
# Run all contract tests using the testing environment
php artisan test --env=testing --filter=Contracts

# Run specific test file
php artisan test --env=testing --filter=ArtistContractTest

# Run specific test
php artisan test --env=testing --filter="client registration"
```

The `--env=testing` flag loads `.env.testing` which has the correct database (`inkedin_test`) and dummy AWS/S3 settings configured.

### Export Fixtures

```bash
# Export fixtures locally
EXPORT_FIXTURES=true php artisan test --env=testing --filter=Contracts

# Upload to S3 after exporting
php artisan fixtures:export --upload --branch=develop

# Or do both in one command
php artisan fixtures:export --upload --branch=main
```

### View Exported Fixtures

```bash
# List all fixtures
find tests/fixtures -name "*.json"

# View a fixture
cat tests/fixtures/artist/detail.json | jq
```

## Writing Contract Tests

### Basic Structure

Contract tests use `DatabaseTransactions` (configured globally in `Pest.php`) which rolls back changes after each test instead of wiping/recreating the database. This is faster and requires the test database to have the schema already set up.

```php
<?php

use App\Models\Artist;
use App\Models\User;

// DatabaseTransactions is applied via Pest.php for all Contracts tests
// Do NOT add uses(RefreshDatabase::class) here

beforeEach(function () {
    // Set up test data - will be rolled back after each test
    $this->artist = Artist::factory()->create();
    $this->user = User::factory()->create();
});

describe('Artist API Contracts', function () {

    it('GET /api/artists/{id} returns correct structure', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'artist' => [
                    'id',
                    'name',
                    'slug',
                    'username',
                ]
            ]);

        // Export fixture for frontend tests
        exportFixture('artist/detail.json', $response->json());
    });

});
```

### Testing Authenticated Endpoints

```php
it('GET /api/users/{id} returns profile for authenticated user', function () {
    $this->actingAs($this->user, 'sanctum');

    $response = $this->getJson("/api/users/{$this->user->id}");

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email']
        ]);

    exportFixture('user/profile.json', $response->json());
});
```

### Testing Mutations

```php
it('PUT /api/artists/{id}/settings updates correctly', function () {
    $this->actingAs($this->artist, 'sanctum');

    $response = $this->putJson("/api/artists/{$this->artist->id}/settings", [
        'books_open' => false,
        'hourly_rate' => 200,
    ]);

    $response->assertOk();

    // Verify database was updated
    $this->artist->settings->refresh();
    expect($this->artist->settings->books_open)->toBeFalse();
    expect($this->artist->settings->hourly_rate)->toBe(200);

    exportFixture('artist/settings-update-response.json', $response->json());
});
```

### Testing Response Variations

```php
it('returns different structure for owner vs public', function () {
    // Public view (unauthenticated)
    $publicResponse = $this->getJson("/api/artists/{$this->artist->id}/settings");
    $publicResponse->assertOk();
    exportFixture('artist/settings-public.json', $publicResponse->json());

    // Owner view (authenticated)
    $this->actingAs($this->artist, 'sanctum');
    $ownerResponse = $this->getJson("/api/artists/{$this->artist->id}/settings");
    $ownerResponse->assertOk();
    exportFixture('artist/settings-owner.json', $ownerResponse->json());

    // Owner should see more fields
    expect(array_keys($ownerResponse->json('data')))
        ->toHaveCount(greaterThan: count(array_keys($publicResponse->json('data'))));
});
```

## Fixture Export Function

The `exportFixture()` function is defined in `tests/Pest.php`:

```php
function exportFixture(string $filename, array $data): void
{
    if (!env('EXPORT_FIXTURES', false)) {
        return;
    }

    $path = base_path("tests/fixtures/{$filename}");
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
```

## CI/CD Integration

### GitHub Actions Workflow

The workflow at `.github/workflows/export-fixtures.yml` runs when these paths change:

- `app/Http/Controllers/**`
- `app/Http/Resources/**`
- `routes/**`
- `tests/Feature/Contracts/**`
- `database/migrations/**`

```yaml
name: Export API Fixtures

on:
  push:
    branches: [main, develop]
    paths:
      - 'app/Http/Controllers/**'
      - 'app/Http/Resources/**'
      - 'routes/**'
      - 'tests/Feature/Contracts/**'
      - 'database/migrations/**'
  workflow_dispatch:  # Manual trigger
```

### S3 Structure

Fixtures are uploaded to:

```
s3://inked-in-images/
  fixtures/
    develop/
      artist/
        detail.json
        search.json
        settings-owner.json
        working-hours.json
        dashboard-stats.json
      client/
        dashboard.json
        favorites.json
        wishlist.json
        suggested-artists.json
      tattoo/
        detail.json
        search.json
        create-response.json
        update-response.json
      user/
        profile.json
        update-response.json
    main/
      ...
    latest/
      ...
```

### Required Secrets

Add these to the GitHub repository:

- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`

### Manual Trigger

1. Go to GitHub > Actions > Export API Fixtures
2. Click "Run workflow"
3. Select branch
4. Click "Run workflow"

## Current Contract Tests

### AuthContractTest (10 tests)

| Test | Endpoint | Auth | Fixture |
|------|----------|------|---------|
| Client registration | POST /api/register | No | auth/register-client.json |
| Artist registration | POST /api/register | No | auth/register-artist.json |
| Registration validation | POST /api/register | No | auth/register-validation-error.json |
| Duplicate email | POST /api/register | No | auth/register-duplicate-email.json |
| Login success | POST /api/login | No | auth/login-success.json |
| Login requires verification | POST /api/login | No | auth/login-requires-verification.json |
| Login invalid credentials | POST /api/login | No | auth/login-invalid-credentials.json |
| Check email available | POST /api/check-availability | No | auth/check-email-available.json |
| Check email taken | POST /api/check-availability | No | auth/check-email-taken.json |
| Check username available | POST /api/check-availability | No | auth/check-username-available.json |

### ArtistContractTest (7 tests, 2 skipped)

| Test | Endpoint | Auth | Fixture | Status |
|------|----------|------|---------|--------|
| Artist detail | GET /api/artists/{id}?db=true | No | artist/detail.json | Pass |
| Artist by slug | GET /api/artists/{slug} | No | - | *Skipped* (requires ES) |
| Artist search | POST /api/artists | No | artist/search.json | *Skipped* (requires ES) |
| Working hours | GET /api/artists/{id}/working-hours | No | artist/working-hours.json | Pass |
| Settings (owner) | GET /api/artists/{id}/settings | Yes | artist/settings-owner.json | Pass |
| Update settings | PUT /api/artists/{id}/settings | Yes | - | Pass |
| Dashboard stats | GET /api/artists/{id}/dashboard-stats | Yes | artist/dashboard-stats.json | Pass |

### ClientDashboardContractTest (7 tests)

| Test | Endpoint | Auth | Fixture |
|------|----------|------|---------|
| Dashboard | GET /api/client/dashboard | Yes | client/dashboard.json |
| Favorites | GET /api/client/favorites | Yes | client/favorites.json |
| Wishlist | GET /api/client/wishlist | Yes | client/wishlist.json |
| Suggested artists | GET /api/client/suggested-artists | Yes | client/suggested-artists.json |
| Toggle favorite | POST /api/users/favorites/artist | Yes | - |
| Add to wishlist | POST /api/client/wishlist | Yes | - |
| Remove from wishlist | DELETE /api/client/wishlist/{id} | Yes | - |

### TattooContractTest (4 tests, 2 skipped)

| Test | Endpoint | Auth | Fixture | Status |
|------|----------|------|---------|--------|
| Tattoo detail | GET /api/tattoos/{id} | No | tattoo/detail.json | Pass |
| Tattoo search | POST /api/tattoos | No | tattoo/search.json | *Skipped* (requires ES) |
| Create tattoo | POST /api/tattoos/create | Yes | - | *Skipped* (requires multipart) |
| Update tattoo | PUT /api/tattoos/{id} | Yes | tattoo/update-response.json | Pass |

### UserContractTest (2 tests)

| Test | Endpoint | Auth | Fixture |
|------|----------|------|---------|
| User profile | GET /api/users/{id} | Yes | user/profile.json |
| Update profile | PUT /api/users/{id} | Yes | user/update-response.json |

## Adding New Contract Tests

### Step 1: Create Test File

```php
// tests/Feature/Contracts/NewFeatureContractTest.php
<?php

use App\Models\YourModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->model = YourModel::factory()->create();
});

describe('New Feature API Contracts', function () {
    it('GET /api/your-endpoint returns correct structure', function () {
        $response = $this->getJson('/api/your-endpoint');

        $response->assertOk()
            ->assertJsonStructure(['expected', 'fields']);

        exportFixture('feature/endpoint.json', $response->json());
    });
});
```

### Step 2: Run Tests

```bash
php artisan test --filter=NewFeatureContractTest
```

### Step 3: Export Fixtures

```bash
EXPORT_FIXTURES=true php artisan test --filter=NewFeatureContractTest
```

### Step 4: Update Frontend

1. Add fixture import to `inked-in-www/nextjs/tests/fixtures/api/index.ts`
2. Update `inked-in-www/nextjs/docs/api-fixtures.md` with new fixture

## Critical Fields

Always verify these fields in artist/user responses:

| Field | Purpose | Must Be Present |
|-------|---------|-----------------|
| `id` | API operations, database keys | Always |
| `slug` | URL routing (`/artists/{slug}`) | Always |
| `username` | @mentions, display | Always |
| `name` | Display name | Always |

These fields were previously inconsistent, causing frontend regressions.

## Troubleshooting

### "REFUSING TO RUN TESTS" Error

The test suite has a safeguard that prevents running against the production database:

```
╔══════════════════════════════════════════════════════════════════╗
║  CRITICAL: REFUSING TO RUN TESTS                                 ║
║  Connected to database: inkedin                                  ║
║  Tests MUST use 'inkedin_test' database.                         ║
╚══════════════════════════════════════════════════════════════════╝
```

**Fix:** Always use `DB_DATABASE=inkedin_test` when running tests:

```bash
DB_DATABASE=inkedin_test php artisan test --filter=Contracts
```

### Test Database Not Set Up

If you see migration or table errors, set up the test database:

```bash
# Create database and grant permissions
mysql -u root -ppassword -e "CREATE DATABASE IF NOT EXISTS inkedin_test; GRANT ALL PRIVILEGES ON inkedin_test.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

# Run migrations
DB_DATABASE=inkedin_test php artisan migrate
```

### Tests Fail with 401 Unauthorized

The `VerifyAppToken` middleware is disabled in tests via `TestCase.php`:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->withoutMiddleware(VerifyAppToken::class);
}
```

### Elasticsearch-Dependent Tests

Some endpoints use Elasticsearch which is not available in the test environment. These tests are skipped:

- Artist search (`POST /api/artists`)
- Artist slug lookup (`GET /api/artists/{slug}`)
- Tattoo search (`POST /api/tattoos`)

For artist detail by ID, use `?db=true` query parameter to bypass Elasticsearch and query the database directly.

**Note:** Search fixtures (`artist/search.json`, `tattoo/search.json`) must be generated manually in an environment with Elasticsearch available, or copied from CI.

### Artist Factory Errors with studio_id

The `Artist` model uses a pivot table (`users_studios`) for studio relationships, not a direct `studio_id` column. When creating test artists with studio associations:

```php
// WRONG - studio_id doesn't exist on users table
$artist = Artist::factory()->create(['studio_id' => $studio->id]);

// CORRECT - use pivot table
$artist = Artist::factory()->create();
$studio->artists()->attach($artist->id, ['is_verified' => true]);
```

### Database Migration Errors

If you see foreign key errors, check migration order in `database/migrations/`.

## Related Documentation

- [inked-in-www: API Fixtures](../../../inked-in-www/nextjs/docs/api-fixtures.md)
- [Pest Documentation](https://pestphp.com/docs/)
- [Laravel Testing](https://laravel.com/docs/testing)

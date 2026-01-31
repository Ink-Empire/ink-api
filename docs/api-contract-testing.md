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
│       ├── ArtistContractTest.php     # Artist endpoint contracts
│       ├── ClientDashboardContractTest.php  # Dashboard contracts
│       ├── TattooContractTest.php     # Tattoo endpoint contracts
│       └── UserContractTest.php       # User profile contracts
├── fixtures/                           # Exported JSON fixtures
│   ├── artist/
│   ├── client/
│   ├── tattoo/
│   └── user/
├── Pest.php                           # Pest configuration
└── TestCase.php                       # Base test case
```

## Running Tests

### Run Contract Tests

```bash
# Run all contract tests
php artisan test --filter=Contracts

# Run specific test file
php artisan test --filter=ArtistContractTest

# Run in Docker
./vendor/bin/sail test --filter=Contracts
```

### Export Fixtures

```bash
# Export fixtures locally
php artisan fixtures:export

# Export and upload to S3 (for frontend to pull)
php artisan fixtures:export --upload

# Upload to specific branch folder
php artisan fixtures:export --upload --branch=main

# Manual export (alternative)
EXPORT_FIXTURES=true php artisan test --filter=Contracts
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

```php
<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set up test data
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

### ArtistContractTest (7 tests)

| Test | Endpoint | Auth | Fixture |
|------|----------|------|---------|
| Artist detail | GET /api/artists/{id} | No | artist/detail.json |
| Artist by slug | GET /api/artists/{slug} | No | - |
| Artist search | POST /api/artists | No | artist/search.json |
| Working hours | GET /api/artists/{id}/working-hours | No | artist/working-hours.json |
| Settings (owner) | GET /api/artists/{id}/settings | Yes | artist/settings-owner.json |
| Update settings | PUT /api/artists/{id}/settings | Yes | - |
| Dashboard stats | GET /api/artists/{id}/dashboard-stats | Yes | artist/dashboard-stats.json |

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

### TattooContractTest (4 tests)

| Test | Endpoint | Auth | Fixture |
|------|----------|------|---------|
| Tattoo detail | GET /api/tattoos/{id} | No | tattoo/detail.json |
| Tattoo search | POST /api/tattoos | No | tattoo/search.json |
| Create tattoo | POST /api/tattoos/create | Yes | tattoo/create-response.json |
| Update tattoo | PUT /api/tattoos/{id} | Yes | tattoo/update-response.json |

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

Some endpoints (search, detail by slug) use Elasticsearch. In test environment:

1. Tests may return empty/null data
2. Use database queries where possible
3. Focus on response structure, not data content

### Database Migration Errors

If you see foreign key errors, check migration order in `database/migrations/`.

## Related Documentation

- [inked-in-www: API Fixtures](../../../inked-in-www/nextjs/docs/api-fixtures.md)
- [Pest Documentation](https://pestphp.com/docs/)
- [Laravel Testing](https://laravel.com/docs/testing)

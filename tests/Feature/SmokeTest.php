<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyAppToken;
use App\Http\Middleware\VerifyCsrfToken;
use Elasticsearch\Client;
use Tests\TestCase;

/**
 * Smoke tests for critical API endpoints.
 * Run: php artisan test --filter=SmokeTest
 *
 * Note: These tests require Elasticsearch to be running.
 * For quick smoke tests against a running server, use: php artisan smoke:test
 */
class SmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyAppToken::class, VerifyCsrfToken::class]);

        if (!$this->isElasticsearchAvailable()) {
            $this->markTestSkipped('Elasticsearch is not available. Run: php artisan smoke:test instead.');
        }
    }

    protected function isElasticsearchAvailable(): bool
    {
        try {
            $client = app(Client::class);
            $client->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function test_tattoos_search_returns_valid_response(): void
    {
        $response = $this->postJson('/api/tattoos', []);

        $response->assertSuccessful()
                 ->assertJsonStructure(['response']);
    }

    public function test_artists_search_returns_valid_response(): void
    {
        $response = $this->postJson('/api/artists', []);

        $response->assertSuccessful()
                 ->assertJsonStructure(['response']);
    }

    public function test_single_tattoo_returns_valid_response(): void
    {
        // Get a tattoo ID from search
        $searchResponse = $this->postJson('/api/tattoos', []);
        $tattooId = $searchResponse->json('response.0.id');

        if (!$tattooId) {
            $this->markTestSkipped('No tattoos available in database.');
        }

        $response = $this->getJson("/api/tattoos/{$tattooId}");
        $response->assertSuccessful();
    }

    public function test_single_artist_returns_valid_response(): void
    {
        // Get an artist ID from search
        $searchResponse = $this->postJson('/api/artists', []);
        $artistId = $searchResponse->json('response.0.id');

        if (!$artistId) {
            $this->markTestSkipped('No artists available in database.');
        }

        $response = $this->getJson("/api/artists/{$artistId}");
        $response->assertSuccessful();
    }

    public function test_tags_index_returns_valid_response(): void
    {
        $response = $this->getJson('/api/tags');

        $response->assertSuccessful()
                 ->assertJsonStructure(['success', 'data']);
    }

    public function test_tags_featured_returns_valid_response(): void
    {
        $response = $this->getJson('/api/tags/featured');

        $response->assertSuccessful()
                 ->assertJsonStructure(['success', 'data']);
    }

    public function test_tags_search_returns_valid_response(): void
    {
        $response = $this->getJson('/api/tags/search?q=traditional');

        $response->assertSuccessful()
                 ->assertJsonStructure(['success', 'data']);
    }

    public function test_styles_index_returns_valid_response(): void
    {
        $response = $this->getJson('/api/styles');

        $response->assertSuccessful();
    }

    public function test_countries_index_returns_valid_response(): void
    {
        $response = $this->getJson('/api/countries');

        $response->assertSuccessful();
    }
}

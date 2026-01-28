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

    public function test_elastic_search_returns_valid_response(): void
    {
        $response = $this->postJson('/api/elastic', [
            'model' => 'tattoo'
        ]);

        $response->assertSuccessful();
    }

    public function test_tattoos_search_with_filters_returns_valid_response(): void
    {
        $response = $this->postJson('/api/tattoos', [
            'styles' => [1],
            'location' => 'New York',
        ]);

        $response->assertSuccessful()
                 ->assertJsonStructure(['response']);
    }

    public function test_artists_search_with_filters_returns_valid_response(): void
    {
        $response = $this->postJson('/api/artists', [
            'location' => 'Los Angeles',
        ]);

        $response->assertSuccessful()
                 ->assertJsonStructure(['response']);
    }
}

<?php

use App\Models\Style;
use App\Models\Tag;

beforeEach(function () {
    $this->styles = Style::factory()->count(5)->create();
});

describe('Tags (Stories 12.1-12.3)', function () {

    it('GET /api/tags returns tags with correct structure', function () {
        $response = $this->getJson('/api/tags');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);

        exportFixture('search/tags-index.json', $response->json());
    });

    it('GET /api/tags/featured returns featured tags', function () {
        $response = $this->getJson('/api/tags/featured');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);

        exportFixture('search/tags-featured.json', $response->json());
    });

    it('GET /api/tags/search?q=test returns correct structure', function () {
        $response = $this->getJson('/api/tags/search?q=test');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);

        exportFixture('search/tags-search.json', $response->json());
    });

});

describe('Styles', function () {

    it('GET /api/styles returns styles from database', function () {
        $response = $this->getJson('/api/styles');

        $response->assertOk()
            ->assertJsonStructure([
                'styles',
            ]);

        expect($response->json('styles'))->toBeArray();
        expect(count($response->json('styles')))->toBeGreaterThanOrEqual(5);

        exportFixture('search/styles-index.json', $response->json());
    });

});

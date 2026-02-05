<?php

namespace Tests\Feature;

// use Tests\Traits\RefreshTestDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Test that the API is running - styles endpoint is public
        $response = $this->getJson('/api/styles');

        $response->assertStatus(200);
    }
}

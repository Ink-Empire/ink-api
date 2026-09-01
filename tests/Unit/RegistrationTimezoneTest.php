<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class RegistrationTimezoneTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Seventy eight of seventy nine existing users had no timezone, which made
     * the appointment sync fall back to UTC and put bookings on artists'
     * calendars hours out. Capturing it at signup stops that regrowing.
     */
    public function test_it_stores_the_timezone_sent_at_signup(): void
    {
        $this->postJson('/api/register', $this->payload(['timezone' => 'America/New_York']))
            ->assertSuccessful();

        $this->assertSame('America/New_York', User::where('email', 'artist@example.com')->first()->timezone);
    }

    public function test_it_registers_without_a_timezone(): void
    {
        $this->postJson('/api/register', $this->payload())
            ->assertSuccessful();

        $this->assertNull(User::where('email', 'artist@example.com')->first()->timezone);
    }

    /**
     * A bad value would be stored and then fail every Carbon::parse using it,
     * so it is rejected at the door rather than at sync time.
     */
    public function test_it_rejects_a_timezone_that_is_not_real(): void
    {
        $this->postJson('/api/register', $this->payload(['timezone' => 'Mars/Olympus_Mons']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Artist',
            'email' => 'artist@example.com',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'username' => 'testartist',
            'slug' => 'testartist',
            'has_accepted_toc' => true,
            'has_accepted_privacy_policy' => true,
        ], $overrides);
    }
}

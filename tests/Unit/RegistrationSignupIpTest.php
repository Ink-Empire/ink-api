<?php

namespace Tests\Unit;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class RegistrationSignupIpTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Two studio accounts with the same name and near-identical addresses were
     * created minutes apart and there was no way to tell whether they came
     * from one place. Registration recorded no request metadata, the view and
     * impression tables were empty because neither account browsed anything,
     * and nginx writes no per-site access log.
     */
    public function test_it_records_the_client_address_and_user_agent_at_signup(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/register', $this->payload(), ['User-Agent' => 'TestBrowser/1.0'])
            ->assertSuccessful();

        $user = User::where('email', 'artist@example.com')->first();

        $this->assertSame('203.0.113.10', $user->signup_ip);
        $this->assertSame('TestBrowser/1.0', $user->signup_user_agent);
    }

    /**
     * TrustProxies has no proxies configured, because nothing fronts the API.
     * A client can send X-Forwarded-For anyway, and trusting it would let the
     * recorded address be whatever the client felt like claiming. If a CDN or
     * load balancer is ever put in front, this test is the one that has to
     * change, alongside TrustProxies.
     */
    public function test_it_ignores_a_forwarded_header_from_an_untrusted_client(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/register', $this->payload(), ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSuccessful();

        $this->assertSame(
            '203.0.113.10',
            User::where('email', 'artist@example.com')->first()->signup_ip
        );
    }

    public function test_it_registers_without_a_user_agent(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/register', $this->payload())
            ->assertSuccessful();

        $this->assertSame(
            '203.0.113.10',
            User::where('email', 'artist@example.com')->first()->signup_ip
        );
    }

    /**
     * An oversized header would otherwise be stored whole.
     */
    public function test_it_caps_an_oversized_user_agent(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/register', $this->payload(), ['User-Agent' => str_repeat('a', 4000)])
            ->assertSuccessful();

        $this->assertSame(
            1000,
            strlen(User::where('email', 'artist@example.com')->first()->signup_user_agent)
        );
    }

    /**
     * This is operator-facing data for investigating abuse. It has no business
     * in a profile response, and the registration response returns nothing but
     * the new id.
     */
    public function test_it_keeps_the_signup_metadata_out_of_user_facing_responses(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/register', $this->payload());

        $this->assertArrayNotHasKey('signup_ip', $response->json());
        $this->assertArrayNotHasKey('signup_user_agent', $response->json());

        $user = User::where('email', 'artist@example.com')->first();
        $profile = (new UserResource($user))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('signup_ip', $profile);
        $this->assertArrayNotHasKey('signup_user_agent', $profile);
        $this->assertArrayNotHasKey('signup_platform', $profile);
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

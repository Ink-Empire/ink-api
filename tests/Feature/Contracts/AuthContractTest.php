<?php

use App\Models\Style;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// DatabaseTransactions is applied via Pest.php for all Contracts tests

beforeEach(function () {
    Notification::fake();

    $this->styles = Style::factory()->count(3)->create();

    $this->existingUser = User::factory()->create([
        'email' => 'existing@test.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->unverifiedUser = User::factory()->create([
        'email' => 'unverified@test.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => null,
    ]);
});

describe('Registration API Contracts', function () {

    it('POST /api/register returns correct structure for client registration', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Client',
            'email' => 'newclient@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'username' => 'testclient',
            'slug' => 'testclient',
            'type' => 'user',
            'location' => 'New York, NY',
            'selected_styles' => [$this->styles->first()->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'requires_verification',
                'email',
                'user' => ['id'],
                'token',
            ]);

        exportFixture('auth/register-client.json', $response->json());
    });

    it('POST /api/register returns correct structure for artist registration', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Artist',
            'email' => 'newartist@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'username' => 'testartist',
            'slug' => 'testartist',
            'type' => 'artist',
            'about' => 'I am a tattoo artist specializing in traditional styles.',
            'location' => 'New York, NY',
            'selected_styles' => $this->styles->pluck('id')->toArray(),
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'requires_verification',
                'email',
                'user' => ['id'],
                'token',
            ]);

        exportFixture('auth/register-artist.json', $response->json());
    });

    it('POST /api/register returns validation errors for invalid data', function () {
        $response = $this->postJson('/api/register', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'weak',
            'username' => 'existing@invalid!',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors',
            ]);

        exportFixture('auth/register-validation-error.json', $response->json());
    });

    it('POST /api/register returns error for duplicate email', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'existing@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'username' => 'newuser',
            'slug' => 'newuser',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        exportFixture('auth/register-duplicate-email.json', $response->json());
    });

});

describe('Login API Contracts', function () {

    it('POST /api/login returns correct structure for verified user', function () {
        $response = $this->postJson('/api/login', [
            'email' => 'existing@test.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'username',
                ],
                'token',
                'message',
            ]);

        exportFixture('auth/login-success.json', $response->json());
    });

    it('POST /api/login returns verification required for unverified user', function () {
        $response = $this->postJson('/api/login', [
            'email' => 'unverified@test.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403)
            ->assertJsonStructure([
                'message',
                'requires_verification',
                'email',
            ]);

        exportFixture('auth/login-requires-verification.json', $response->json());
    });

    it('POST /api/login returns error for invalid credentials', function () {
        $response = $this->postJson('/api/login', [
            'email' => 'existing@test.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors',
            ]);

        exportFixture('auth/login-invalid-credentials.json', $response->json());
    });

});

describe('Check Availability API Contracts', function () {

    it('POST /api/check-availability returns available for new email', function () {
        $response = $this->postJson('/api/check-availability', [
            'email' => 'brandnew@test.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'available' => true,
                'field' => 'email',
            ]);

        exportFixture('auth/check-email-available.json', $response->json());
    });

    it('POST /api/check-availability returns unavailable for existing email', function () {
        $response = $this->postJson('/api/check-availability', [
            'email' => 'existing@test.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'available' => false,
                'field' => 'email',
            ]);

        exportFixture('auth/check-email-taken.json', $response->json());
    });

    it('POST /api/check-availability returns available for new username', function () {
        $response = $this->postJson('/api/check-availability', [
            'username' => 'brandnewuser',
        ]);

        $response->assertOk()
            ->assertJson([
                'available' => true,
                'field' => 'username',
            ]);

        exportFixture('auth/check-username-available.json', $response->json());
    });

});

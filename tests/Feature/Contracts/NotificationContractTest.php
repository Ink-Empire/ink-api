<?php

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    // Create a mix of read and unread notifications
    $this->unreadNotifications = collect();
    $this->readNotifications = collect();

    for ($i = 0; $i < 3; $i++) {
        $this->unreadNotifications->push(
            DatabaseNotification::create([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'type' => 'App\Notifications\TattooApprovedNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $this->user->id,
                'data' => [
                    'type' => 'tattoo_approved',
                    'message' => "Artist {$i} approved your tattoo",
                    'actor_name' => "Artist {$i}",
                    'actor_image' => null,
                    'entity_type' => 'tattoo',
                    'entity_id' => $i + 1,
                ],
                'read_at' => null,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ])
        );
    }

    for ($i = 0; $i < 2; $i++) {
        $this->readNotifications->push(
            DatabaseNotification::create([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'type' => 'App\Notifications\TattooRejectedNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $this->user->id,
                'data' => [
                    'type' => 'tattoo_rejected',
                    'message' => "Artist {$i} declined your tag",
                    'actor_name' => "Artist {$i}",
                    'actor_image' => null,
                    'entity_type' => 'tattoo',
                    'entity_id' => $i + 100,
                ],
                'read_at' => now(),
                'created_at' => now()->subHours($i + 1),
                'updated_at' => now()->subHours($i + 1),
            ])
        );
    }
});

describe('Notification List', function () {

    it('GET /api/notifications returns paginated notifications', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'message',
                        'actor_name',
                        'entity_type',
                        'entity_id',
                        'read_at',
                        'created_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        expect($response->json('data'))->toHaveCount(5);

        exportFixture('notifications/list.json', $response->json());
    });

    it('GET /api/notifications returns empty for user with no notifications', function () {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum');

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);

        exportFixture('notifications/list-empty.json', $response->json());
    });

    it('GET /api/notifications returns 401 for unauthenticated user', function () {
        $response = $this->getJson('/api/notifications');

        $response->assertStatus(401);
    });

});

describe('Notification Unread Count', function () {

    it('GET /api/notifications/unread-count returns correct count', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertOk()
            ->assertJson(['unread_count' => 3]);

        exportFixture('notifications/unread-count.json', $response->json());
    });

    it('GET /api/notifications/unread-count returns 401 for unauthenticated user', function () {
        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertStatus(401);
    });

});

describe('Notification Mark Read', function () {

    it('POST /api/notifications/mark-read marks all as read', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/notifications/mark-read');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $unreadCount = $this->user->unreadNotifications()->count();
        expect($unreadCount)->toBe(0);

        exportFixture('notifications/mark-all-read.json', $response->json());
    });

    it('POST /api/notifications/mark-read marks single notification as read', function () {
        $this->actingAs($this->user, 'sanctum');

        $notificationId = $this->unreadNotifications->first()->id;

        $response = $this->postJson('/api/notifications/mark-read', [
            'notification_id' => $notificationId,
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $notification = DatabaseNotification::find($notificationId);
        expect($notification->read_at)->not->toBeNull();

        // Other unread notifications should remain unread
        $remainingUnread = $this->user->unreadNotifications()->count();
        expect($remainingUnread)->toBe(2);

        exportFixture('notifications/mark-single-read.json', $response->json());
    });

    it('POST /api/notifications/mark-read returns 401 for unauthenticated user', function () {
        $response = $this->postJson('/api/notifications/mark-read');

        $response->assertStatus(401);
    });

});

<?php

use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\StudioAvailability;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
});

test('hours saved from the dashboard come back on the public studio', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/studios/{$this->studio->id}/working-hours", [
            'availability' => [
                ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'is_day_off' => false],
                ['day_of_week' => 0, 'start_time' => '00:00:00', 'end_time' => '00:00:00', 'is_day_off' => true],
            ],
        ])
        ->assertOk();

    $response = $this->getJson("/api/studios/{$this->studio->slug}")->assertOk();

    $hours = collect($response->json('studio.hours'));

    expect($hours->firstWhere('day', 'Monday')['hours'])->toBe('9:00 AM - 5:00 PM')
        ->and($hours->firstWhere('day', 'Sunday')['hours'])->toBe('Closed');
});

test('a studio with no hours set returns an empty list rather than stale data', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.hours', []);
});

test('public studio reads are not publicly cached', function () {
    $response = $this->getJson("/api/studios/{$this->studio->slug}");

    $cacheControl = $response->headers->get('Cache-Control') ?? '';

    expect($cacheControl)->not->toContain('max-age=60');
});

test('hours are ordered from Sunday through Saturday', function () {
    foreach ([3, 0, 6, 1] as $day) {
        StudioAvailability::create([
            'studio_id' => $this->studio->id,
            'day_of_week' => $day,
            'start_time' => '10:00:00',
            'end_time' => '18:00:00',
            'is_day_off' => false,
        ]);
    }

    $days = collect($this->getJson("/api/studios/{$this->studio->slug}")->json('studio.hours'))
        ->pluck('day_id')
        ->all();

    expect($days)->toBe([0, 1, 3, 6]);
});

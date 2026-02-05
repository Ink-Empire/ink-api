<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserBlock;
use Tests\Traits\RefreshTestDatabase;
use Tests\TestCase;

class UserBlockingTest extends TestCase
{
    use RefreshTestDatabase;

    public function test_user_can_block_another_user(): void
    {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();

        $blocker->block($blocked->id, 'Test reason');

        $this->assertTrue($blocker->hasBlocked($blocked->id));
        $this->assertDatabaseHas('user_blocks', [
            'blocker_id' => $blocker->id,
            'blocked_id' => $blocked->id,
            'reason' => 'Test reason',
        ]);
    }

    public function test_user_can_unblock_another_user(): void
    {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();

        $blocker->block($blocked->id);
        $this->assertTrue($blocker->hasBlocked($blocked->id));

        $blocker->unblock($blocked->id);
        $this->assertFalse($blocker->hasBlocked($blocked->id));
    }

    public function test_is_blocked_by_returns_true_when_blocked(): void
    {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();

        $blocker->block($blocked->id);

        $this->assertTrue($blocked->isBlockedBy($blocker->id));
    }

    public function test_get_all_blocked_ids_returns_both_directions(): void
    {
        $user = User::factory()->create();
        $blockedByUser = User::factory()->create();
        $blocksUser = User::factory()->create();

        // User blocks someone
        $user->block($blockedByUser->id);

        // Someone blocks user
        $blocksUser->block($user->id);

        $blockedIds = $user->getAllBlockedIds();

        $this->assertContains($blockedByUser->id, $blockedIds);
        $this->assertContains($blocksUser->id, $blockedIds);
    }

    public function test_is_blocked_returns_true_for_either_direction(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Test when user blocks other
        $user->block($other->id);
        $this->assertTrue($user->isBlocked($other->id));

        $user->unblock($other->id);

        // Test when other blocks user
        $other->block($user->id);
        $this->assertTrue($user->isBlocked($other->id));
    }

    public function test_scope_not_blocked_by_filters_blocked_users(): void
    {
        $user = User::factory()->create();
        $artist1 = User::factory()->asArtist()->create();
        $artist2 = User::factory()->asArtist()->create();
        $artist3 = User::factory()->asArtist()->create();

        // User blocks artist1
        $user->block($artist1->id);

        // Query artists excluding blocked ones
        $artists = User::artist()->notBlockedBy($user)->get();

        $this->assertFalse($artists->contains('id', $artist1->id));
        $this->assertTrue($artists->contains('id', $artist2->id));
        $this->assertTrue($artists->contains('id', $artist3->id));
    }

    public function test_scope_not_blocked_by_returns_all_when_no_user(): void
    {
        $artist1 = User::factory()->asArtist()->create();
        $artist2 = User::factory()->asArtist()->create();

        // Query with null user (guest)
        $artists = User::artist()->notBlockedBy(null)->get();

        $this->assertTrue($artists->contains('id', $artist1->id));
        $this->assertTrue($artists->contains('id', $artist2->id));
    }

    public function test_blocking_same_user_twice_does_not_duplicate(): void
    {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();

        $blocker->block($blocked->id);
        $blocker->block($blocked->id);

        $this->assertEquals(1, UserBlock::where('blocker_id', $blocker->id)
            ->where('blocked_id', $blocked->id)
            ->count());
    }
}

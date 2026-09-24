<?php

use App\Enums\StudioHoldStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hold takes a studio out of public view without touching the account, the
 * studio row, its images or the owner's login. It is reversible by design.
 *
 * Deliberately not reusing studios.is_verified. That column has existed since
 * the first studios migration, is never written by any code path, and already
 * feeds a "verified" badge on the public studio page. Overloading it would
 * conflate a business being verified with a studio being visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->string('hold_status', 32)
                ->default(StudioHoldStatus::Active->value)
                ->after('is_claimed')
                ->index();
            $table->text('hold_reason')->nullable()->after('hold_status');
            $table->timestamp('held_at')->nullable()->after('hold_reason');
            $table->unsignedBigInteger('held_by_id')->nullable()->after('held_at');
            $table->timestamp('hold_lifted_at')->nullable()->after('held_by_id');
            $table->unsignedBigInteger('hold_lifted_by_id')->nullable()->after('hold_lifted_at');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropIndex(['hold_status']);
            $table->dropColumn([
                'hold_status',
                'hold_reason',
                'held_at',
                'held_by_id',
                'hold_lifted_at',
                'hold_lifted_by_id',
            ]);
        });
    }
};

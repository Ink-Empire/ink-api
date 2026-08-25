<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize the two claimed studio slugs that predate centralized slug
 * generation, so every studio slug follows the same hyphenated convention
 * before the unique index lands and before nested studio URLs ship.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $renames = [
        'reidstudios' => 'reid-studios',
        'tattoocolosseum' => 'tattoo-colosseum',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            if (DB::table('studios')->where('slug', $to)->exists()) {
                continue;
            }

            DB::table('studios')->where('slug', $from)->update(['slug' => $to]);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            if (DB::table('studios')->where('slug', $from)->exists()) {
                continue;
            }

            DB::table('studios')->where('slug', $to)->update(['slug' => $from]);
        }
    }
};

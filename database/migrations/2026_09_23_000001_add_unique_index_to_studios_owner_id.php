<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A studio account represents the business, so an owner holds exactly one
     * studio. The application has always assumed this - User::ownedStudio is a
     * hasOne - but the column only carried an ordinary foreign key, so the next
     * write path to drift would have done so silently.
     *
     * Unclaimed Google Places listings carry a null owner_id, and MySQL permits
     * any number of nulls in a unique index, so they are unaffected.
     */
    public function up(): void
    {
        $duplicates = DB::table('studios')
            ->whereNotNull('owner_id')
            ->select('owner_id')
            ->groupBy('owner_id')
            ->havingRaw('count(*) > 1')
            ->pluck('owner_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make studios.owner_id unique: these users own more than one studio: '
                .$duplicates->implode(', ')
                .'. Resolve them first, then run this migration.'
            );
        }

        Schema::table('studios', function (Blueprint $table) {
            $table->unique('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropUnique(['owner_id']);
        });
    }
};

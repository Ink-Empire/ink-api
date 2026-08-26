<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a studio's movable page sections render in.
 *
 * Null means the studio has never rearranged anything, which is every studio
 * at the point this runs, so there is nothing to backfill: the order is
 * resolved against the default list on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->json('section_order')->nullable()->after('template');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('section_order');
        });
    }
};

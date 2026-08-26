<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stack each half-width section sits in, keyed by section.
 *
 * Sparse, like section_widths: only a section the studio actually moved into a
 * column appears here. Everything else falls back to alternating down the
 * band, which reproduces the row grid this replaced, so nothing needs
 * backfilling and no existing page changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->json('section_columns')->nullable()->after('section_widths');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('section_columns');
        });
    }
};

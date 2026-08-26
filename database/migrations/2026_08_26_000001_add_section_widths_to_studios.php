<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section width overrides, keyed by section.
 *
 * Sparse: only a section the studio actually resized appears here, and every
 * other one falls back to the width its section type ships with. Null means
 * nothing has been resized, which is every studio at the point this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->json('section_widths')->nullable()->after('section_order');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('section_widths');
        });
    }
};

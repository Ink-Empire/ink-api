<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which row of its band a section sits in.
 *
 * Together with section_columns this makes a section's position a cell rather
 * than a place in a queue, which is what lets a column hold a gap: a studio can
 * put one card in the second row of the right column while the first row of
 * that column stays empty. Sparse like the others - absent means packed in
 * order, which is what every page did before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->json('section_rows')->nullable()->after('section_bands');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('section_rows');
        });
    }
};

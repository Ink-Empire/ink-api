<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which band each section sits in, where the studio has overridden its layout.
 *
 * Sparse, like the other two arrangement columns: absent means the layout
 * decides, so no existing page changes and there is nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->json('section_bands')->nullable()->after('section_columns');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('section_bands');
        });
    }
};

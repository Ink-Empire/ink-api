<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio slug is the root of the public studio URL and of everything
 * nested beneath it, so a duplicate slug makes those child URLs ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->unique('slug', 'studios_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropUnique('studios_slug_unique');
        });
    }
};

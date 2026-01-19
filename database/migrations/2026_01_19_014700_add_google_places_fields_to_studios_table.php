<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->boolean('is_claimed')->default(true)->after('is_verified');
            $table->string('google_place_id')->nullable()->unique()->after('is_claimed');
            $table->decimal('rating', 2, 1)->nullable()->after('google_place_id');
            $table->string('website')->nullable()->after('phone');
            $table->index('is_claimed');
            $table->index('location_lat_long');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropIndex(['is_claimed']);
            $table->dropIndex(['location_lat_long']);
            $table->dropColumn(['is_claimed', 'google_place_id', 'rating', 'website']);
        });
    }
};

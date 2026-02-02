<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tattoo_leads', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('is_active');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');

            $table->index(['lat', 'lng']);
        });

        // Backfill existing leads with user location data
        DB::statement("
            UPDATE tattoo_leads tl
            JOIN users u ON tl.user_id = u.id
            SET tl.lat = SUBSTRING_INDEX(u.location_lat_long, ',', 1),
                tl.lng = SUBSTRING_INDEX(u.location_lat_long, ',', -1)
            WHERE u.location_lat_long IS NOT NULL
              AND u.location_lat_long != ''
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tattoo_leads', function (Blueprint $table) {
            $table->dropIndex(['lat', 'lng']);
            $table->dropColumn(['lat', 'lng']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->boolean('seeking_guest_spots')->default(false)->after('minimum_session');
            $table->json('guest_spot_regions')->nullable()->after('seeking_guest_spots');
        });
    }

    public function down(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->dropColumn(['seeking_guest_spots', 'guest_spot_regions']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_invitations', function (Blueprint $table) {
            $table->string('location_lat_long')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('artist_invitations', function (Blueprint $table) {
            $table->dropColumn('location_lat_long');
        });
    }
};

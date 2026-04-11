<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoo_leads', function (Blueprint $table) {
            $table->string('location')->nullable()->after('lng');
            $table->string('location_lat_long')->nullable()->after('location');
            $table->integer('radius')->default(50)->after('location_lat_long');
            $table->string('radius_unit', 10)->default('mi')->after('radius');
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_leads', function (Blueprint $table) {
            $table->dropColumn(['location', 'location_lat_long', 'radius', 'radius_unit']);
        });
    }
};

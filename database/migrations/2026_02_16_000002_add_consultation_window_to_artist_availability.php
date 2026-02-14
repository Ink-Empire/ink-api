<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_availability', function (Blueprint $table) {
            $table->time('consultation_start_time')->nullable()->after('end_time');
            $table->time('consultation_end_time')->nullable()->after('consultation_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('artist_availability', function (Blueprint $table) {
            $table->dropColumn(['consultation_start_time', 'consultation_end_time']);
        });
    }
};

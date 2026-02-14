<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('consultation_duration')->default(30)->after('consultation_fee');
        });
    }

    public function down(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->dropColumn('consultation_duration');
        });
    }
};

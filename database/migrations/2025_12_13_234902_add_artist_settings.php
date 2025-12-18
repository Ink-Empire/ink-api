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
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->integer('hourly_rate')->after('accepts_appointments')->default(0);
            $table->integer('deposit_amount')->after('hourly_rate')->default(0);
            $table->integer('consultation_fee')->after('deposit_amount')->default(0);
            $table->integer('minimum_session')->nullable()->after('consultation_fee')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
            $table->dropColumn('deposit_amount');
            $table->dropColumn('consultation_fee');
            $table->dropColumn('minimum_session');
        });
    }
};

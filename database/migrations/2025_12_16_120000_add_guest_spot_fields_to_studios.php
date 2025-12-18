<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->boolean('seeking_guest_artists')->default(false)->after('phone');
            $table->text('guest_spot_details')->nullable()->after('seeking_guest_artists');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn(['seeking_guest_artists', 'guest_spot_details']);
        });
    }
};

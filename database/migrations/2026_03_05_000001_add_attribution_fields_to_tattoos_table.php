<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->string('attributed_artist_name')->nullable()->after('approval_status');
            $table->string('attributed_studio_name')->nullable()->after('attributed_artist_name');
            $table->string('attributed_location')->nullable()->after('attributed_studio_name');
        });
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropColumn(['attributed_artist_name', 'attributed_studio_name', 'attributed_location']);
        });
    }
};

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
        Schema::table('users_studios', function (Blueprint $table) {
            $table->enum('initiated_by', ['artist', 'studio'])->default('artist')->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_studios', function (Blueprint $table) {
            $table->dropColumn('initiated_by');
        });
    }
};

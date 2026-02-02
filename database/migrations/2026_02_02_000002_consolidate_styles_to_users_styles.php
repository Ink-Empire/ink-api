<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Consolidate artists_styles into users_styles and drop the redundant table.
     */
    public function up(): void
    {
        // Copy any styles from artists_styles that don't already exist in users_styles
        DB::statement("
            INSERT IGNORE INTO users_styles (user_id, style_id)
            SELECT artist_id, style_id FROM artists_styles
        ");

        // Drop the redundant table
        Schema::dropIfExists('artists_styles');
    }

    /**
     * Reverse the migration - recreate artists_styles and copy data back.
     */
    public function down(): void
    {
        // Recreate the artists_styles table
        Schema::create('artists_styles', function (Blueprint $table) {
            $table->foreignId('artist_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('style_id')->constrained('styles')->onDelete('cascade');
            $table->primary(['artist_id', 'style_id']);
        });

        // Copy artist styles back (only for users who are artists - type_id = 2)
        DB::statement("
            INSERT INTO artists_styles (artist_id, style_id)
            SELECT us.user_id, us.style_id
            FROM users_styles us
            JOIN users u ON us.user_id = u.id
            WHERE u.type_id = 2
        ");
    }
};

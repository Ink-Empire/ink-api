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
        Schema::table('appointments', function (Blueprint $table) {
            // Drop the existing enum constraint and recreate with 'pending' included
            $table->enum('status', ['pending', 'booked', 'completed', 'cancelled'])
                  ->default('pending')
                  ->change();
            
            // Make studio_id nullable
            $table->foreignId('studio_id')
                  ->nullable()
                  ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Revert back to the original enum values
            $table->enum('status', ['booked', 'completed', 'cancelled'])
                  ->default('booked')
                  ->change();
            
            // Revert studio_id back to not nullable (if needed)
            $table->foreignId('studio_id')
                  ->nullable(false)
                  ->change();
        });
    }
};

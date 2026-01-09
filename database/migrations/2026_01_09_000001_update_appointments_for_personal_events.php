<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates appointments table to support personal events (no client)
     * and additional event types like 'appointment' and 'other'.
     */
    public function up(): void
    {
        // Make client_id nullable for personal/blocking events
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        // Update the type enum to include 'appointment' and 'other'
        // MySQL requires raw SQL to modify enum values
        DB::statement("ALTER TABLE appointments MODIFY COLUMN type ENUM('tattoo', 'consultation', 'appointment', 'other') DEFAULT 'tattoo'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reverting will fail if there are records with 'appointment' or 'other' types
        DB::statement("ALTER TABLE appointments MODIFY COLUMN type ENUM('tattoo', 'consultation') DEFAULT 'tattoo'");

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};

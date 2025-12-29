<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add cascade delete to business_hours.studio_id foreign key.
     * This ensures business hours are automatically deleted when a studio is deleted.
     */
    public function up(): void
    {
        Schema::table('business_hours', function (Blueprint $table) {
            // Drop existing foreign key
            $table->dropForeign(['studio_id']);

            // Re-add with cascade delete
            $table->foreign('studio_id')
                ->references('id')
                ->on('studios')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('business_hours', function (Blueprint $table) {
            // Drop cascading foreign key
            $table->dropForeign(['studio_id']);

            // Re-add without cascade (default behavior)
            $table->foreign('studio_id')
                ->references('id')
                ->on('studios');
        });
    }
};

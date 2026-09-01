<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the metadata column, which held the location, description and link of
 * every event on a connected artist's personal calendar.
 *
 * Nothing ever read it. Availability comes from the times, and the title is the
 * only detail shown back to the artist. Storing the rest meant a database
 * breach exposed the content of every private appointment on every connected
 * calendar for no product benefit, and it is the kind of over collection that
 * draws pushback during Google OAuth verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_calendar_events', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }

    /**
     * Restores the column but not its contents. The data it held was deleted
     * deliberately and is not recoverable from here.
     */
    public function down(): void
    {
        Schema::table('external_calendar_events', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });
    }
};

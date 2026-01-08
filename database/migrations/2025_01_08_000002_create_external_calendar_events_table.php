<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
            $table->string('vendor_event_id');
            $table->string('title')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('status')->default('confirmed'); // confirmed, tentative, cancelled
            $table->enum('source', ['google', 'inkedin'])->default('google');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['calendar_connection_id', 'vendor_event_id'], 'ext_cal_events_connection_vendor_unique');
            $table->index(['starts_at', 'ends_at'], 'ext_cal_events_time_range_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_calendar_events');
    }
};

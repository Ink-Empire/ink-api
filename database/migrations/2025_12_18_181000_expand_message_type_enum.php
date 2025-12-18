<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the message type enum to include new types
        DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM(
            'text',
            'image',
            'booking_card',
            'deposit_request',
            'system',
            'design_share',
            'price_quote',
            'appointment_reminder',
            'appointment_confirmed',
            'appointment_cancelled',
            'deposit_received',
            'aftercare'
        ) DEFAULT 'text'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM(
            'text',
            'image',
            'booking_card',
            'deposit_request'
        ) DEFAULT 'text'");
    }
};

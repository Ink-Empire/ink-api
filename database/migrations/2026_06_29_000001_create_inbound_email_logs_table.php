<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('message_uid')->unique();
            $table->string('sender_email');
            $table->string('sender_name')->nullable();
            $table->unsignedInteger('image_count')->default(0);
            $table->boolean('is_processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('bulk_upload_id')->nullable()->constrained('bulk_uploads')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_logs');
    }
};

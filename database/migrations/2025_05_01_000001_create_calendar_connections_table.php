<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_connections')) {
            return;
        }

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider')->default('google'); // google, outlook (future)
            $table->string('provider_account_id');
            $table->string('provider_email');
            $table->string('calendar_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');
            $table->string('sync_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('sync_enabled')->default(true);

            // Webhook fields
            $table->string('webhook_channel_id')->nullable();
            $table->string('webhook_resource_id')->nullable();
            $table->timestamp('webhook_expires_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};

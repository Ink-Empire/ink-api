<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'sender_id', 'created_at'], 'messages_conv_sender_created_index');
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->index(['user_id', 'deleted_at'], 'conv_participants_user_deleted_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conv_sender_created_index');
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropIndex('conv_participants_user_deleted_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });
    }
};

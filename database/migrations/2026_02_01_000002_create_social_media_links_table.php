<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('platform', 20);
            $table->string('username', 100);
            $table->timestamps();

            $table->unique(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_links');
    }
};

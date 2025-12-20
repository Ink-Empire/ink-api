<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('artist_id')->constrained('users')->onDelete('cascade');
            $table->boolean('notify_booking_open')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'artist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_wishlists');
    }
};

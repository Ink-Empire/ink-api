<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Styles table
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        // User styles pivot
        Schema::create('users_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('style_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // Tattoos table (minimal)
        Schema::create('tattoos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('image_id')->nullable()->constrained('images');
            $table->string('title')->nullable();
            $table->timestamps();
        });

        // User favorite artists pivot
        Schema::create('users_artists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('artist_id');
            $table->foreign('artist_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });

        // User favorite tattoos pivot
        Schema::create('users_tattoos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tattoo_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_tattoos');
        Schema::dropIfExists('users_artists');
        Schema::dropIfExists('tattoos');
        Schema::dropIfExists('users_styles');
        Schema::dropIfExists('styles');
    }
};

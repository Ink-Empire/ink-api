<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('artist_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained('users')->onDelete('cascade');
            $table->boolean('books_open')->default(false);
            $table->boolean('accepts_walk_ins')->default(false);
            $table->boolean('accepts_deposits')->default(false);
            $table->boolean('accepts_consultations')->default(false);
            $table->boolean('accepts_appointments')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_settings');
    }
};

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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('artist_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('studio_id')->constrained('studios')->onDelete('cascade');
            $table->foreignId('tattoo_id')->nullable()->constrained('tattoos')->onDelete('cascade');
            $table->date('date');
            $table->enum('status', ['booked', 'completed', 'cancelled'])->default('booked');
            $table->enum('type', ['tattoo', 'consultation'])->default('tattoo');
            $table->boolean('all_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

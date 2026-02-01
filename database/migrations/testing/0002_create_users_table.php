<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('type_id')->constrained('types');
            $table->string('location')->nullable();
            $table->string('location_lat_long')->nullable();
            $table->text('about')->nullable();
            $table->string('phone')->nullable();
            $table->string('studio_name')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->foreignId('image_id')->nullable()->constrained('images');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

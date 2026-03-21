<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tag_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_user_id')->constrained('users');
            $table->string('name');
            $table->string('color', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tag_categories');
    }
};

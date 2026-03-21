<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('tag_category_id')->constrained('user_tag_categories')->cascadeOnDelete();
            $table->string('label', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tags');
    }
};

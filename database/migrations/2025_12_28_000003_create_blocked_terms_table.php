<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term')->unique();
            $table->string('category')->nullable(); // e.g., 'explicit', 'violence', 'hate'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_terms');
    }
};

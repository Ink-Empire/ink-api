<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_spotlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->onDelete('cascade');
            $table->string('spotlightable_type'); // 'artist' or 'tattoo'
            $table->unsignedBigInteger('spotlightable_id');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['studio_id', 'spotlightable_type']);
            $table->unique(['studio_id', 'spotlightable_type', 'spotlightable_id'], 'studio_spotlight_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_spotlights');
    }
};

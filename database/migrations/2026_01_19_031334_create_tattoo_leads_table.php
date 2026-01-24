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
        Schema::create('tattoo_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('timing', ['week', 'month', 'year'])->nullable();
            $table->date('interested_by')->nullable();
            $table->boolean('allow_artist_contact')->default(false);
            $table->json('style_ids')->nullable();
            $table->json('tag_ids')->nullable();
            $table->json('custom_themes')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'allow_artist_contact']);
            $table->index('interested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tattoo_leads');
    }
};

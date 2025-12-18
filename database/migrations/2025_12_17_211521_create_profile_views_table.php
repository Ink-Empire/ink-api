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
        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->morphs('viewable'); // viewable_type (App\Models\User, App\Models\Tattoo, App\Models\Studio) + viewable_id
            $table->string('ip_address', 45)->nullable(); // For anonymous view tracking
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamps();

            // Index for efficient querying
            $table->index(['viewable_type', 'viewable_id', 'created_at']);
            $table->index(['viewer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_views');
    }
};

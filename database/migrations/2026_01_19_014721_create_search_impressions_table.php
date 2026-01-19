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
        Schema::create('search_impressions', function (Blueprint $table) {
            $table->id();
            $table->morphs('impressionable'); // impressionable_type, impressionable_id
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('search_location')->nullable();
            $table->string('search_coords')->nullable();
            $table->json('search_filters')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['impressionable_type', 'impressionable_id', 'created_at'], 'impressions_type_id_created_idx');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_impressions');
    }
};

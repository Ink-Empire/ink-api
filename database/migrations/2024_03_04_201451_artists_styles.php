<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('artists_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained('users');
            $table->foreignId('style_id')->constrained();
            $table->timestamp('created_at')->default(\DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(\DB::raw('NULL ON UPDATE CURRENT_TIMESTAMP'))->nullable();

            $table->unique(['artist_id', 'style_id']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artists_styles');
    }
};

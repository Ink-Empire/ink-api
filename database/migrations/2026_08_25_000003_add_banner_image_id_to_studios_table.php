<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The banner is the wide header image on the public studio page. It is a
 * separate reference from image_id, which stays the square studio mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->foreignId('banner_image_id')
                ->nullable()
                ->after('image_id')
                ->constrained('images')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropForeign(['banner_image_id']);
            $table->dropColumn('banner_image_id');
        });
    }
};

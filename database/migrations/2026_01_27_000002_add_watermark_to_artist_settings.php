<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->foreignId('watermark_image_id')->nullable()->after('guest_spot_regions')->constrained('images')->nullOnDelete();
            $table->integer('watermark_opacity')->default(50)->after('watermark_image_id'); // 0-100
            $table->string('watermark_position')->default('bottom-right')->after('watermark_opacity'); // bottom-right, bottom-left, top-right, top-left, center
            $table->boolean('watermark_enabled')->default(false)->after('watermark_position');
        });
    }

    public function down(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->dropForeign(['watermark_image_id']);
            $table->dropColumn(['watermark_image_id', 'watermark_opacity', 'watermark_position', 'watermark_enabled']);
        });
    }
};

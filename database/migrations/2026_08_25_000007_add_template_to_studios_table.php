<?php

use App\Enums\StudioTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the hand-built page layouts a studio uses. Everyone starts on
 * Portfolio, which is the layout every studio page has always had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->string('template')->default(StudioTemplate::Portfolio->value)->after('banner_image_id');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};

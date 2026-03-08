<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_upload_items', function (Blueprint $table) {
            $table->json('ai_suggested_styles')->nullable()->after('ai_suggested_tags');
            $table->string('zip_path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bulk_upload_items', function (Blueprint $table) {
            $table->dropColumn('ai_suggested_styles');
            $table->string('zip_path', 500)->nullable(false)->change();
        });
    }
};

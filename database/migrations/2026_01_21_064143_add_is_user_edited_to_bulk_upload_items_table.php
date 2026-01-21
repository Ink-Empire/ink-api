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
        Schema::table('bulk_upload_items', function (Blueprint $table) {
            $table->boolean('is_edited')->default(false)->after('is_skipped');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulk_upload_items', function (Blueprint $table) {
            $table->dropColumn('is_edited');
        });
    }
};

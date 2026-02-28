<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            // Make artist_id nullable so users can upload without an artist
            $table->unsignedBigInteger('artist_id')->nullable()->change();

            // Make description nullable
            $table->text('description')->nullable()->change();

            // Track who uploaded the tattoo
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->after('artist_id');
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();

            // Approval status for user uploads
            // approved = visible in main feed + artist profile
            // pending = awaiting artist approval
            // user_only = visible only on user's profile (untagged uploads or rejected)
            $table->string('approval_status', 20)->default('approved')->after('uploaded_by_user_id');

            $table->index('uploaded_by_user_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by_user_id']);
            $table->dropIndex(['uploaded_by_user_id']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['uploaded_by_user_id', 'approval_status']);

            $table->unsignedBigInteger('artist_id')->nullable(false)->change();
            $table->text('description')->nullable(false)->change();
        });
    }
};

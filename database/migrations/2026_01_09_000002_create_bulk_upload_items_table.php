<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_upload_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_upload_id')->constrained('bulk_uploads')->onDelete('cascade');

            // Grouping for carousel posts
            $table->string('post_group_id', 100)->nullable();
            $table->boolean('is_primary_in_group')->default(true);

            // Processing state
            $table->boolean('is_cataloged')->default(true);
            $table->boolean('is_processed')->default(false);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_skipped')->default(false);

            // References (populated as we progress)
            $table->foreignId('image_id')->nullable()->constrained('images')->onDelete('set null');
            $table->foreignId('tattoo_id')->nullable()->constrained('tattoos')->onDelete('set null');

            // Original source data (from ZIP scan)
            $table->string('zip_path', 500);
            $table->integer('file_size_bytes')->nullable();
            $table->text('original_caption')->nullable();
            $table->timestamp('original_timestamp')->nullable();

            // User-editable fields
            $table->text('description')->nullable();
            $table->foreignId('placement_id')->nullable()->constrained('placements')->onDelete('set null');
            $table->foreignId('primary_style_id')->nullable()->constrained('styles')->onDelete('set null');
            $table->json('additional_style_ids')->nullable();

            // Tags
            $table->json('ai_suggested_tags')->nullable();
            $table->json('approved_tag_ids')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bulk_upload_id', 'is_processed']);
            $table->index(['bulk_upload_id', 'is_published']);
            $table->index(['bulk_upload_id', 'post_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_upload_items');
    }
};

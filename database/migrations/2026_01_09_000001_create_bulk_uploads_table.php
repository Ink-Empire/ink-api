<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained('users')->onDelete('cascade');
            $table->string('source', 50)->default('manual'); // 'instagram', 'manual'
            $table->enum('status', ['scanning', 'cataloged', 'processing', 'ready', 'completed', 'failed'])->default('scanning');

            // Counts
            $table->integer('total_images')->default(0);
            $table->integer('cataloged_images')->default(0);
            $table->integer('processed_images')->default(0);
            $table->integer('published_images')->default(0);

            // ZIP storage
            $table->string('zip_filename')->nullable();
            $table->bigInteger('zip_size_bytes')->nullable();
            $table->timestamp('zip_expires_at')->nullable();

            $table->string('original_filename')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index(['artist_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_uploads');
    }
};

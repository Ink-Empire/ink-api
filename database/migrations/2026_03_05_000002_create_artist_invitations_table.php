<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tattoo_id')->constrained('tattoos')->onDelete('cascade');
            $table->foreignId('invited_by_user_id')->constrained('users')->onDelete('cascade');
            $table->string('artist_name');
            $table->string('studio_name')->nullable();
            $table->string('location')->nullable();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_invitations');
    }
};

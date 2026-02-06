<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->unsignedInteger('saved_count')->default(0);
            $table->index('saved_count');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('saved_count')->default(0);
            $table->index('saved_count');
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->unsignedInteger('saved_count')->default(0);
            $table->index('saved_count');
        });
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropIndex(['saved_count']);
            $table->dropColumn('saved_count');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['saved_count']);
            $table->dropColumn('saved_count');
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->dropIndex(['saved_count']);
            $table->dropColumn('saved_count');
        });
    }
};

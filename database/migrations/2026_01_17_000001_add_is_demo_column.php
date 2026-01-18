<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_admin')->index();
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('id')->index();
        });

        Schema::table('tattoos', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('id')->index();
        });

        // Set all existing data as demo for first run
        DB::table('users')->update(['is_demo' => true]);
        DB::table('studios')->update(['is_demo' => true]);
        DB::table('tattoos')->update(['is_demo' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });

        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};

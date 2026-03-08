<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            if (!Schema::hasColumn('tattoos', 'is_visible')) {
                $table->boolean('is_visible')->default(true)->after('approval_status');
            }
        });

        DB::table('tattoos')->where('approval_status', 'approved')->update(['is_visible' => true]);
        DB::table('tattoos')->where('approval_status', '!=', 'approved')->update(['is_visible' => false]);
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropColumn('is_visible');
        });
    }
};

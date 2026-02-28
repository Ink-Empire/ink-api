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
            $table->renameColumn('is_public', 'is_visible');
        });

        DB::table('tattoos')->where('approval_status', 'approved')->update(['is_visible' => true]);
        DB::table('tattoos')->where('approval_status', '!=', 'approved')->update(['is_visible' => false]);
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->renameColumn('is_visible', 'is_public');
        });
    }
};

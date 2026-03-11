<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_invitations', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('artist_invitations', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};

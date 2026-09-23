<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_ip', 45)->nullable()->after('signup_platform');
            $table->text('signup_user_agent')->nullable()->after('signup_ip');

            // The question this exists to answer is "what else came from this
            // address", which is a lookup by value rather than by user.
            $table->index('signup_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_ip']);
            $table->dropColumn(['signup_ip', 'signup_user_agent']);
        });
    }
};

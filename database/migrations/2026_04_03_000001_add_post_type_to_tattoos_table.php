<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->string('post_type', 20)->default('portfolio')->after('is_demo')->index();
            $table->decimal('flash_price', 10, 2)->nullable()->after('post_type');
            $table->string('flash_size', 255)->nullable()->after('flash_price');
            $table->unsignedBigInteger('tattoo_lead_id')->nullable()->after('flash_size');

            $table->foreign('tattoo_lead_id')
                ->references('id')
                ->on('tattoo_leads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tattoos', function (Blueprint $table) {
            $table->dropForeign(['tattoo_lead_id']);
            $table->dropColumn(['post_type', 'flash_price', 'flash_size', 'tattoo_lead_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    const NAMES = [
        'client',
        'artist',
        'shop'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('types')) {
            foreach(self::NAMES as $name) {
                DB::table('types')->insert(
                    ['name' => $name]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('types')) {
            DB::table('title_synonyms')->whereIn('name', self::NAMES)->delete();
        }
    }
};

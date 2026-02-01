<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Seed default types
        DB::table('types')->insert([
            ['id' => 1, 'name' => 'client'],
            ['id' => 2, 'name' => 'artist'],
            ['id' => 3, 'name' => 'studio'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('types');
    }
};

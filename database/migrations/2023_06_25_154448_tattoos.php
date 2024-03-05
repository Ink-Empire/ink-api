<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tattoos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description');
            $table->string('placement')->nullable();
            $table->foreignId('artist_id')->constrained('users', 'id');
            $table->foreignId('studio_id')->constrained();
            $table->foreignId('primary_style_id')->constrained('styles', 'id');
            $table->foreignId('primary_subject_id')->constrained('subjects', 'id');
            $table->foreignId('primary_image_id')->constrained('images', 'id');
            $table->timestamp('created_at')->default(\DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(\DB::raw('NULL ON UPDATE CURRENT_TIMESTAMP'))->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tattoos');
    }
};

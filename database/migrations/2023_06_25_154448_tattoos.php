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
            $table->string('title')->nullable();
            $table->string('description');
            $table->string('placement')->nullable();
            $table->foreignId('artist_id')->constrained('users', 'id')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('studio_id')->constrained()->onDelete('no action')->onUpdate('no action')->nullable();
            $table->foreignId('primary_style_id')->constrained('styles', 'id')->onDelete('no action')->onUpdate('no action');
            $table->foreignId('primary_image_id')->constrained('images', 'id')->onDelete('cascade')->onUpdate('cascade');
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

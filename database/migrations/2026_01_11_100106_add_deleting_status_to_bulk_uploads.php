<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bulk_uploads MODIFY COLUMN status ENUM('scanning', 'cataloged', 'processing', 'ready', 'completed', 'failed', 'deleting') DEFAULT 'scanning'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bulk_uploads MODIFY COLUMN status ENUM('scanning', 'cataloged', 'processing', 'ready', 'completed', 'failed') DEFAULT 'scanning'");
    }
};

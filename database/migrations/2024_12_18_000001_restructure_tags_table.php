<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Restructure tags from denormalized (tattoo_id, tag string)
     * to normalized (master tags table + pivot table)
     */
    public function up(): void
    {
        // Step 1: Rename existing tags table
        Schema::rename('tags', 'tags_legacy');

        // Step 2: Create new master tags table
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Step 3: Create pivot table
        Schema::create('tattoos_tags', function (Blueprint $table) {
            $table->foreignId('tattoo_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->primary(['tattoo_id', 'tag_id']);
        });

        // Step 4: Migrate existing data
        $this->migrateExistingTags();

        // Step 5: Drop legacy table
        Schema::dropIfExists('tags_legacy');
    }

    /**
     * Migrate existing tag strings to new normalized structure
     */
    private function migrateExistingTags(): void
    {
        // Get all unique tags from legacy table
        $legacyTags = DB::table('tags_legacy')
            ->select('tag')
            ->distinct()
            ->whereNotNull('tag')
            ->where('tag', '!=', '')
            ->get();

        // Create master tag records
        $tagIdMap = [];
        foreach ($legacyTags as $legacyTag) {
            $name = strtolower(trim($legacyTag->tag));
            $slug = Str::slug($name);

            // Skip if slug already exists (handles duplicates like "Skull" and "skull")
            if (isset($tagIdMap[$slug])) {
                continue;
            }

            $id = DB::table('tags')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tagIdMap[$slug] = $id;
        }

        // Migrate tattoo-tag relationships
        $relationships = DB::table('tags_legacy')
            ->select('tattoo_id', 'tag')
            ->whereNotNull('tag')
            ->where('tag', '!=', '')
            ->get();

        $pivotRecords = [];
        $seenPairs = [];

        foreach ($relationships as $rel) {
            $slug = Str::slug(strtolower(trim($rel->tag)));

            if (!isset($tagIdMap[$slug])) {
                continue;
            }

            $tagId = $tagIdMap[$slug];
            $pairKey = "{$rel->tattoo_id}-{$tagId}";

            // Skip duplicates
            if (isset($seenPairs[$pairKey])) {
                continue;
            }

            $pivotRecords[] = [
                'tattoo_id' => $rel->tattoo_id,
                'tag_id' => $tagId,
            ];
            $seenPairs[$pairKey] = true;
        }

        // Batch insert pivot records
        if (!empty($pivotRecords)) {
            foreach (array_chunk($pivotRecords, 500) as $chunk) {
                DB::table('tattoos_tags')->insert($chunk);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate legacy table structure
        Schema::create('tags_legacy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tattoo_id')->constrained()->onDelete('cascade')->onUpdate('cascade');
            $table->string('tag');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('NULL ON UPDATE CURRENT_TIMESTAMP'))->nullable();
            $table->unique(['tattoo_id', 'tag']);
        });

        // Migrate data back to denormalized format
        $relationships = DB::table('tattoos_tags')
            ->join('tags', 'tattoos_tags.tag_id', '=', 'tags.id')
            ->select('tattoos_tags.tattoo_id', 'tags.name as tag')
            ->get();

        foreach ($relationships as $rel) {
            DB::table('tags_legacy')->insert([
                'tattoo_id' => $rel->tattoo_id,
                'tag' => $rel->tag,
                'created_at' => now(),
            ]);
        }

        // Drop new tables
        Schema::dropIfExists('tattoos_tags');
        Schema::dropIfExists('tags');

        // Rename back
        Schema::rename('tags_legacy', 'tags');
    }
};

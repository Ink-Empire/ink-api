<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use File;

class TattoosTagsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/tattoos_tags.json");
        $tattoosWithTags = json_decode($json);

        if (!Schema::hasTable('tattoos_tags') || !Schema::hasTable('tags')) {
            return;
        }

        // Build a lookup map of tag slug => tag id
        $tagLookup = DB::table('tags')->pluck('id', 'slug')->toArray();

        foreach ($tattoosWithTags as $entry) {
            $tattooId = $entry->tattoo_id;

            foreach ($entry->tags as $tagName) {
                // Convert tag name to slug (same logic as TagSeeder)
                $slug = Str::slug(strtolower($tagName));

                if (isset($tagLookup[$slug])) {
                    DB::table('tattoos_tags')->insertOrIgnore([
                        'tattoo_id' => $tattooId,
                        'tag_id' => $tagLookup[$slug],
                    ]);
                }
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\BlockedTerm;
use Illuminate\Database\Seeder;

class BlockedTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            // Explicit content
            ['term' => 'nude', 'category' => 'explicit'],
            ['term' => 'naked', 'category' => 'explicit'],
            ['term' => 'sex', 'category' => 'explicit'],
            ['term' => 'sexual', 'category' => 'explicit'],
            ['term' => 'porn', 'category' => 'explicit'],
            ['term' => 'erotic', 'category' => 'explicit'],
            ['term' => 'explicit', 'category' => 'explicit'],
            ['term' => 'genitals', 'category' => 'explicit'],
            ['term' => 'penis', 'category' => 'explicit'],
            ['term' => 'vagina', 'category' => 'explicit'],
            ['term' => 'breast', 'category' => 'explicit'],
            ['term' => 'nipple', 'category' => 'explicit'],

            // Profanity
            ['term' => 'ass', 'category' => 'profanity'],
            ['term' => 'butt', 'category' => 'profanity'],
            ['term' => 'fuck', 'category' => 'profanity'],
            ['term' => 'shit', 'category' => 'profanity'],
            ['term' => 'dick', 'category' => 'profanity'],
            ['term' => 'cock', 'category' => 'profanity'],
            ['term' => 'pussy', 'category' => 'profanity'],
            ['term' => 'bitch', 'category' => 'profanity'],
            ['term' => 'slut', 'category' => 'profanity'],
            ['term' => 'whore', 'category' => 'profanity'],

            // Hate speech
            ['term' => 'racist', 'category' => 'hate'],
            ['term' => 'nazi', 'category' => 'hate'],
            ['term' => 'hate', 'category' => 'hate'],

            // Violence
            ['term' => 'kill', 'category' => 'violence'],
            ['term' => 'murder', 'category' => 'violence'],
            ['term' => 'rape', 'category' => 'violence'],
            ['term' => 'violence', 'category' => 'violence'],
        ];

        foreach ($terms as $termData) {
            BlockedTerm::firstOrCreate(
                ['term' => $termData['term']],
                $termData
            );
        }
    }
}

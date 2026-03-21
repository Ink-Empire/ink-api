<?php

namespace Database\Seeders;

use App\Models\UserTagCategory;
use Illuminate\Database\Seeder;

class UserTagCategorySeeder extends Seeder
{
    public static function seedForUser(int $userId): void
    {
        $defaults = [
            ['name' => 'Style preferences', 'color' => 'teal', 'sort_order' => 0],
            ['name' => 'Avoid', 'color' => 'coral', 'sort_order' => 1],
            ['name' => 'Personality', 'color' => 'purple', 'sort_order' => 2],
            ['name' => 'Pain notes', 'color' => 'amber', 'sort_order' => 3],
        ];

        foreach ($defaults as $category) {
            UserTagCategory::create([
                'studio_user_id' => $userId,
                ...$category,
            ]);
        }
    }

    public function run(): void
    {
        // For manual seeding — not typically called directly
    }
}

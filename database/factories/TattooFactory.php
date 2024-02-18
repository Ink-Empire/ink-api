<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class TattooFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'title' => fake()->word(),
            'description' => fake()->sentence(5),
            'user_id' => User::factory(),
            'shop_id' => Shop::factory(),
            'style_id' => rand(1,10),
            'theme_id' => rand(1,10),
            'image_id' => Image::factory(),
            'tags' => "",
        ];
    }
}

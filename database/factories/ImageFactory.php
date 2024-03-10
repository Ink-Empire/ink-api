<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Image>
 */
class ImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'filename' => fake()->filePath(),
            'uri' => 'https://loremflickr.com/320/240/tattoo?random=' . random_int(1,1000),
            'is_primary' => 0
        ];
    }
}

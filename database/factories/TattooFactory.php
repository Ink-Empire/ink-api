<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Studio;
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
            'placement' => $this->getBodyPart(),
            'artist_id' => User::factory()->asArtist(),
            'studio_id' => Studio::factory(),
            'primary_style_id' => null,
            'primary_image_id' => Image::factory(),
        ];
    }

    private function getBodyPart()
    {
        $array = [
          'chest',
          'back',
          'elbow',
          'backside',
          'calf',
          'hand',
          'foot'
        ];

        return $array[array_rand($array)];
    }
}

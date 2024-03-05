<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class StudioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->company(),
            'address_id' => 1,
            'about' => fake()->sentence(15),
            'location' => fake()->city() . " " . fake()->country(),
            'email' => fake()->unique()->safeEmail(),

        ];
    }
}

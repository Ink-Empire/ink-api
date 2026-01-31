<?php

namespace Database\Factories;

use App\Enums\UserTypes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $name = fake()->name();
        return [
            'name' => $name,
            'username' => fake()->unique()->userName(),
            'slug' => \Str::slug($name) . '-' . fake()->unique()->randomNumber(5),
            'email' => fake()->unique()->safeEmail(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'type_id' => UserTypes::CLIENT_TYPE_ID,
            'location' => fake()->city() . ', ' . fake()->stateAbbr(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return static
     */
    public function unverified()
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function asArtist()
    {
        return $this->state(function (array $attributes) {
            return [
                'type_id' => UserTypes::ARTIST_TYPE_ID,
            ];
        });
    }
}

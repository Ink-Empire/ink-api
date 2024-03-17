<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'first_name' => "undefined", //todo do we want to remove names from address table?
            'last_name' => "undefined",
            'address1' => "",
            'address2' => "",
            'city' => "",
            'state' => "",
            'postal_code' => "",
            'country_code' => "",
            'phone' => "",
            'is_active' => 1
        ];
    }

    public function generated(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'first_name' => fake()->firstName,
                'last_name' => fake()->lastName,
                'address1' => fake()->streetAddress,
                'address2' => fake()->secondaryAddress,
                'city' => fake()->city,
                'state' => fake()->state,
                'postal_code' => fake()->postcode,
                'country_code' => 'US',
                'phone' => '000-000-0000',
                'is_active' => 1
            ];
        });
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!Schema::hasTable('locations')) {
            return;
        }

        // Helper to insert or get existing location
        $insertOrGet = function (array $data) {
            $existing = DB::table('locations')->where('slug', $data['slug'])->first();
            if ($existing) {
                return $existing->id;
            }
            return DB::table('locations')->insertGetId($data);
        };

        // Regions
        $northAmerica = $insertOrGet([
            'type' => 'region',
            'name' => 'North America',
            'slug' => 'north-america',
            'parent_id' => null,
            'country_code' => null,
            'latitude' => 54.5260,
            'longitude' => -105.2551,
            'timezone' => null,
            'studio_count' => 0,
            'demand_level' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $europe = $insertOrGet([
            'type' => 'region',
            'name' => 'Europe',
            'slug' => 'europe',
            'parent_id' => null,
            'country_code' => null,
            'latitude' => 54.5260,
            'longitude' => 15.2551,
            'timezone' => null,
            'studio_count' => 0,
            'demand_level' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oceania = $insertOrGet([
            'type' => 'region',
            'name' => 'Oceania',
            'slug' => 'oceania',
            'parent_id' => null,
            'country_code' => null,
            'latitude' => -22.7359,
            'longitude' => 140.0188,
            'timezone' => null,
            'studio_count' => 0,
            'demand_level' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Countries
        $usa = $insertOrGet([
            'type' => 'country',
            'name' => 'United States',
            'slug' => 'united-states',
            'parent_id' => $northAmerica,
            'country_code' => 'US',
            'latitude' => 37.0902,
            'longitude' => -95.7129,
            'timezone' => 'America/New_York',
            'studio_count' => 0,
            'demand_level' => 'high',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $canada = $insertOrGet([
            'type' => 'country',
            'name' => 'Canada',
            'slug' => 'canada',
            'parent_id' => $northAmerica,
            'country_code' => 'CA',
            'latitude' => 56.1304,
            'longitude' => -106.3468,
            'timezone' => 'America/Toronto',
            'studio_count' => 0,
            'demand_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uk = $insertOrGet([
            'type' => 'country',
            'name' => 'United Kingdom',
            'slug' => 'united-kingdom',
            'parent_id' => $europe,
            'country_code' => 'GB',
            'latitude' => 55.3781,
            'longitude' => -3.4360,
            'timezone' => 'Europe/London',
            'studio_count' => 0,
            'demand_level' => 'high',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $germany = $insertOrGet([
            'type' => 'country',
            'name' => 'Germany',
            'slug' => 'germany',
            'parent_id' => $europe,
            'country_code' => 'DE',
            'latitude' => 51.1657,
            'longitude' => 10.4515,
            'timezone' => 'Europe/Berlin',
            'studio_count' => 0,
            'demand_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $france = $insertOrGet([
            'type' => 'country',
            'name' => 'France',
            'slug' => 'france',
            'parent_id' => $europe,
            'country_code' => 'FR',
            'latitude' => 46.2276,
            'longitude' => 2.2137,
            'timezone' => 'Europe/Paris',
            'studio_count' => 0,
            'demand_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $australia = $insertOrGet([
            'type' => 'country',
            'name' => 'Australia',
            'slug' => 'australia',
            'parent_id' => $oceania,
            'country_code' => 'AU',
            'latitude' => -25.2744,
            'longitude' => 133.7751,
            'timezone' => 'Australia/Sydney',
            'studio_count' => 0,
            'demand_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cities - USA
        $cities = [
            ['name' => 'Los Angeles', 'slug' => 'los-angeles', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 34.0522, 'lng' => -118.2437, 'tz' => 'America/Los_Angeles', 'demand' => 'high'],
            ['name' => 'New York City', 'slug' => 'new-york-city', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 40.7128, 'lng' => -74.0060, 'tz' => 'America/New_York', 'demand' => 'high'],
            ['name' => 'Chicago', 'slug' => 'chicago', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 41.8781, 'lng' => -87.6298, 'tz' => 'America/Chicago', 'demand' => 'high'],
            ['name' => 'Miami', 'slug' => 'miami', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 25.7617, 'lng' => -80.1918, 'tz' => 'America/New_York', 'demand' => 'high'],
            ['name' => 'San Francisco', 'slug' => 'san-francisco', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 37.7749, 'lng' => -122.4194, 'tz' => 'America/Los_Angeles', 'demand' => 'high'],
            ['name' => 'Seattle', 'slug' => 'seattle', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 47.6062, 'lng' => -122.3321, 'tz' => 'America/Los_Angeles', 'demand' => 'medium'],
            ['name' => 'Austin', 'slug' => 'austin', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 30.2672, 'lng' => -97.7431, 'tz' => 'America/Chicago', 'demand' => 'medium'],
            ['name' => 'Denver', 'slug' => 'denver', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 39.7392, 'lng' => -104.9903, 'tz' => 'America/Denver', 'demand' => 'medium'],
            ['name' => 'Portland', 'slug' => 'portland', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 45.5051, 'lng' => -122.6750, 'tz' => 'America/Los_Angeles', 'demand' => 'medium'],
            ['name' => 'Orlando', 'slug' => 'orlando', 'parent_id' => $usa, 'country_code' => 'US', 'lat' => 28.5383, 'lng' => -81.3792, 'tz' => 'America/New_York', 'demand' => 'medium'],
            // Canada
            ['name' => 'Toronto', 'slug' => 'toronto', 'parent_id' => $canada, 'country_code' => 'CA', 'lat' => 43.6532, 'lng' => -79.3832, 'tz' => 'America/Toronto', 'demand' => 'high'],
            ['name' => 'Vancouver', 'slug' => 'vancouver', 'parent_id' => $canada, 'country_code' => 'CA', 'lat' => 49.2827, 'lng' => -123.1207, 'tz' => 'America/Vancouver', 'demand' => 'medium'],
            // UK
            ['name' => 'London', 'slug' => 'london', 'parent_id' => $uk, 'country_code' => 'GB', 'lat' => 51.5074, 'lng' => -0.1278, 'tz' => 'Europe/London', 'demand' => 'high'],
            // Germany
            ['name' => 'Berlin', 'slug' => 'berlin', 'parent_id' => $germany, 'country_code' => 'DE', 'lat' => 52.5200, 'lng' => 13.4050, 'tz' => 'Europe/Berlin', 'demand' => 'high'],
            // France
            ['name' => 'Paris', 'slug' => 'paris', 'parent_id' => $france, 'country_code' => 'FR', 'lat' => 48.8566, 'lng' => 2.3522, 'tz' => 'Europe/Paris', 'demand' => 'high'],
            // Australia
            ['name' => 'Sydney', 'slug' => 'sydney', 'parent_id' => $australia, 'country_code' => 'AU', 'lat' => -33.8688, 'lng' => 151.2093, 'tz' => 'Australia/Sydney', 'demand' => 'high'],
            ['name' => 'Melbourne', 'slug' => 'melbourne', 'parent_id' => $australia, 'country_code' => 'AU', 'lat' => -37.8136, 'lng' => 144.9631, 'tz' => 'Australia/Melbourne', 'demand' => 'medium'],
        ];

        foreach ($cities as $city) {
            DB::table('locations')->insertOrIgnore([
                'type' => 'city',
                'name' => $city['name'],
                'slug' => $city['slug'],
                'parent_id' => $city['parent_id'],
                'country_code' => $city['country_code'],
                'latitude' => $city['lat'],
                'longitude' => $city['lng'],
                'timezone' => $city['tz'],
                'studio_count' => 0,
                'demand_level' => $city['demand'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

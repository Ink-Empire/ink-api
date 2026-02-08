<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private array $demoUsers = [
        [
            'name' => 'Demo User',
            'username' => 'demouser',
            'slug' => 'demo-user',
            'email' => 'demouser@getinked.in',
            'password' => 'Demouser1!',
            'type_id' => 1,
        ],
        [
            'name' => 'Demo Artist',
            'username' => 'demoartist',
            'slug' => 'demo-artist',
            'email' => 'demoartist@getinked.in',
            'password' => 'Demoartist1!',
            'type_id' => 2,
        ],
        [
            'name' => 'Demo Shop',
            'username' => 'demoshop',
            'slug' => 'demo-shop',
            'email' => 'demoshop@getinked.in',
            'password' => 'Demoshop1!',
            'type_id' => 3,
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->demoUsers as $user) {
            DB::table('users')->insertOrIgnore([
                'name' => $user['name'],
                'username' => $user['username'],
                'slug' => $user['slug'],
                'email' => $user['email'],
                'password' => Hash::make($user['password']),
                'type_id' => $user['type_id'],
                'location' => 'New York, NY',
                'is_email_verified' => true,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('slug', ['demo-user', 'demo-artist', 'demo-shop'])
            ->delete();
    }
};

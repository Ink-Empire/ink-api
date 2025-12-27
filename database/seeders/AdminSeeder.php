<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seed the initial admin user.
     *
     * Configure admin credentials in .env:
     * ADMIN_EMAIL=admin@inkedin.com
     * ADMIN_PASSWORD=your-secure-password
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@inkedin.com');
        $password = env('ADMIN_PASSWORD');

        if (!$password) {
            $this->command->error('ADMIN_PASSWORD not set in .env file. Skipping admin seeder.');
            return;
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'username' => 'admin',
                'password' => Hash::make($password),
                'is_admin' => true,
                'type_id' => 1, // Client type
                'location' => '',
            ]
        );

        $this->command->info("Admin user created/updated: {$admin->email}");
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Administrators — can add/remove users. Change the password after first login.
        $admins = [
            'alex.gordo@cwglobalpeople.com' => 'Alex Gordo',
            'dennies.salenga@cwglobalpeople.com' => 'Dennies Salenga',
        ];

        foreach ($admins as $email => $name) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => User::ROLE_ADMIN,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }

        // Local dev login (not an admin). Remove or change before production use.
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Dev',
                'role' => User::ROLE_USER,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}

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
        // Administrators — can add/remove users. Change the password after first
        // login. The super admin additionally sees org-wide insights (the
        // dashboard's answer-feedback card) and can manage other admins.
        $admins = [
            'alex.gordo@cwglobalpeople.com' => ['Alex Gordo', User::ROLE_SUPER_ADMIN],
            'dennies.salenga@cwglobalpeople.com' => ['Dennies Salenga', User::ROLE_ADMIN],
        ];

        foreach ($admins as $email => [$name, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
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

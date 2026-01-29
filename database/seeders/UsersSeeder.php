<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Sample Driver',
                'email' => 'driver@example.com',
                'phone' => '0712345678',
                'password' => Hash::make('1234'),
                'role' => 'driver',
            ],
            [
                'name' => 'Sample Sacco Manager',
                'email' => 'sacco@example.com',
                'phone' => '0798765432',
                'password' => Hash::make('5678'),
                'role' => 'sacco',
            ],
            [
                'name' => 'Sample Service Person',
                'email' => 'service@example.com',
                'phone' => '0700000000',
                'password' => Hash::make('0000'),
                'role' => 'nishukishe_service_person',
            ],
            [
                'name' => 'Sample Gov Employee',
                'email' => 'gov@example.com',
                'phone' => '0722000000',
                'password' => Hash::make('2222'),
                'role' => 'gov',
            ],
            [
                'name' => 'john doe', //fallback user
                'email' => 'johndoe@nishukishe.com',
                'phone' => '07123456789',
                'password' => Hash::make('e]$K#5?aX^Ysj3R'),
                'role' => 'commuter',
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['email' => $user['email']],  // lookup key
                array_merge($user, [          // insert/update data
                    'is_verified' => true,
                    'is_active' => true,
                ])
            );
        }
    }
}

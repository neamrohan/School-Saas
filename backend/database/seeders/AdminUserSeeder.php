<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@schoolsass.com'],
            [
                'name' => 'Main Admin',
                'password' => Hash::make('Admin@12345'),
                'school_id' => null,
                'role' => 'super_admin',
            ]
        );
    }
}
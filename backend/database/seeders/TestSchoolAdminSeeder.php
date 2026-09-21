<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestSchoolAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'schooladmin@schoolsass.com'],
            [
                'name' => 'Demo School Admin',
                'password' => Hash::make('School@12345'),
                'school_id' => 1,
                'role' => 'school_admin',
            ]
        );
    }
}
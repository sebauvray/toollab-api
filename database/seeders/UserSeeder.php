<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['first_name' => 'Rayane', 'last_name' => 'QOUCHICH', 'email' => 'rayane.qouchich@gmail.com', 'password' => bcrypt('password'), 'access' => true],
            // Décommenté et corrigé les adresses email
            ['first_name' => 'Sebastien', 'last_name' => 'AUVRAY', 'email' => 'sebastien.auvray@gmail.com', 'password' => bcrypt('password'), 'access' => true],
            ['first_name' => 'Younes', 'last_name' => 'SERRA', 'email' => 'younes.serra@gmail.com', 'password' => bcrypt('password'), 'access' => true],
            ['first_name' => 'Redha', 'last_name' => 'EL HANTI', 'email' => 'redha.elhanti@gmail.com', 'password' => bcrypt('password'), 'access' => true],
        ];

        User::insert($users);
    }
}

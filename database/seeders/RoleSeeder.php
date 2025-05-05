<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'Directeur', 'slug' => 'director'],
            ['name' => 'Administrateur', 'slug' => 'admin'],
            ['name' => 'Responsable des inscriptions', 'slug' => 'registar'],
            ['name' => 'Responsable', 'slug' => 'responsible'],
            ['name' => 'Élève', 'slug' => 'student'],
            ['name' => 'Professeur', 'slug' => 'teacher'],
        ];

        Role::insert($roles);
    }
}

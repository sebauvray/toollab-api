<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\School;
use App\Models\Family;
use App\Models\Classroom;
use App\Services\SchoolRoleProvisioner;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ToollabSeeder::class,
        ]);

        // Les seeders rattachent le staff aux modèles globaux : on raccroche
        // chaque école sur ses propres rôles.
        foreach (School::pluck('id') as $schoolId) {
            app(SchoolRoleProvisioner::class)->provision($schoolId);
        }
   }
}

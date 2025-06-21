<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\School;
use App\Models\Family;
use App\Models\Classroom;
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
        ]);
   }
}

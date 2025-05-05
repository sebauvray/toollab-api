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
            UserSeeder::class,
            RoleSeeder::class,
            SchoolSeeder::class,
        ]);

        //Factories
        if (ENV('APP_ENV') !== 'production') {
            User::factory()->count(9)->create();
            School::factory()->count(5)->create();

            $users = User::all();
            $roles = Role::all();
            $schools = School::all();
            $families = Family::all();

            $schools->each(function ($school) use ($schools) {
                // Calculate a random number of classes for this school
                $classCount = max(3, ceil(25 / $schools->count() + random_int(-2, 2)));
                
                Classroom::factory()
                    ->count($classCount)
                    ->forSchool($school)
                    ->create();
            });

            //Get role responisble
            $responsibleRole = $roles->firstWhere('name', 'Responsible');

            if ($responsibleRole && $users->isNotEmpty() && $roles->isNotEmpty() && $schools->isNotEmpty()) {
                foreach ($users as $user) {
                    $randomRole   = $roles->random();
                    $randomSchool = $schools->random();
                     
                    //Create family randomly
                    if (rand(0, 1)) { 
                        $family = Family::create();
                        
                        $user->roles()->create([
                            'role_id' => $responsibleRole->id,
                            'roleable_id' => $family->id,
                            'roleable_type' => 'family'
                        ]);
                    }            
                    // add randoom role
                    $user->roles()->create([
                        'role_id' => $randomRole->id,
                        'roleable_id' => $randomSchool->id,
                        'roleable_type' => 'school'
                    ]);
                }
            }
        }
   }
}

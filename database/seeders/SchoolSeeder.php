<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schools = [
            [
                'name' => 'Aissa Institut',
                'email' => 'contact@aissa-institut.fr',
                'address' => '7-15 avenue de la porte de la vilette',
                'zipcode' => '75019',
                'city' => 'Paris',
                'country' => 'France',
                'logo' => null,
                'access' => true
            ],
        ];

        School::insert($schools);
    }
}

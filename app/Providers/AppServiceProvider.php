<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'school' => 'App\Models\School',
            'classroom' => 'App\Models\Classroom',
            'family' => 'App\Models\Family',
        ]);

//        Relation::enforceMorphMap([
//            'school' => 'App\Models\School',
//            'classroom' => 'App\Models\Classroom',
//            'family' => 'App\Models\Family',
//        ]);
    }
}

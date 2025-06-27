<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserInfo;
use App\Observers\UserInfoObserver;
use App\Observers\UserObserver;
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
        UserInfo::observe(UserInfoObserver::class);
        User::observe(UserObserver::class);

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

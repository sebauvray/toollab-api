<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserInfo;
use App\Observers\UserInfoObserver;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        $this->configureRateLimiters();

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

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by($request->ip() . '|email:' . $email),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));
            return [
                Limit::perMinute(3)->by($request->ip() . '|email:' . $email),
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        RateLimiter::for('token-check', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });
    }
}

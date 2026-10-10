<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserInfo;
use App\Observers\UserInfoObserver;
use App\Observers\UserObserver;
use App\Support\ErrorRecorder;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
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
        $this->trackCurrentJobForErrors();

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

    /**
     * Indique à ErrorRecorder quel job tourne : le worker signale l'exception
     * après JobFailed, donc on ne réinitialise qu'au job suivant ou à la réussite.
     */
    private function trackCurrentJobForErrors(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $command = $event->job->payload()['data']['commandName'] ?? '';
            ErrorRecorder::$currentJob = $event->job->resolveName();
            ErrorRecorder::$currentJobIsMail = in_array($command, [
                \Illuminate\Notifications\SendQueuedNotifications::class,
                \Illuminate\Mail\SendQueuedMailable::class,
            ], true);
        });
        Event::listen(JobProcessed::class, function () {
            ErrorRecorder::$currentJob = null;
            ErrorRecorder::$currentJobIsMail = false;
        });
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

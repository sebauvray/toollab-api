<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\SchoolContext;
use App\Http\Middleware\SchoolYearContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'checkrole' => CheckRole::class,
            'superadmin' => SuperAdmin::class,
            'school' => SchoolContext::class,
            'schoolyear' => SchoolYearContext::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // SchoolContext doit passer avant SubstituteBindings sinon le Route
        // Model Binding s'exécute sans contexte et le global scope renvoie 0 ligne.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            SuperAdmin::class,
            SchoolContext::class,
            SchoolYearContext::class,
            CheckRole::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ressource introuvable',
                ], 404);
            }
        });

        // Gate sur APP_DEBUG plutôt que APP_ENV : on veut sanitizer dans tous les
        // environnements non-debug (staging inclus), pas seulement en prod.
        // Sinon les messages SQL bruts (et les traces) leak au front en staging.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && !config('app.debug')) {

                if ($e instanceof ValidationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Requête invalide',
                        'errors' => $e->errors(),
                    ], 422);
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Non authentifié',
                    ], 401);
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Accès refusé',
                    ], 403);
                }

                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                if ($status >= 500) {
                    Log::error('Unhandled API exception', [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'path' => $request->path(),
                        'user_id' => optional($request->user())->id,
                    ]);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Une erreur est survenue',
                ], $status);
            }
        });
    })
    ->create();

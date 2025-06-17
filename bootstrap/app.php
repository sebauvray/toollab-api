<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
//            \App\Http\Middleware\ApiResponseMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $model = $e->getPrevious()?->getModel();
                if ($model) {
                    $modelName = match ($model) {
                        'App\Models\Classroom' => 'Classroom',
                        'App\Models\Comment' => 'Comment',
                        'App\Models\Family' => 'Family',
                        'App\Models\Role' => 'Role',
                        'App\Models\School' => 'School',
                        'App\Models\User' => 'User',
                        'App\Models\UserInfo' => 'User info',
                        'App\Models\UerRole' => 'User role',
                        default => 'Resource'
                    };
                }

                return response()->json([
                    'status' => 'error',
                    'message' => isset($modelName) ? "{$modelName} not found." : $e->getMessage(),
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && env('APP_ENV') === 'production') {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                if (
                    $e instanceof ValidationException ||
                    $e instanceof \Illuminate\Auth\AuthenticationException ||
                    $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
                ) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ], $status);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'The service encountered an issue. Please contact the administrator.'
                ], 500);
            }
        });
    })
    ->create();

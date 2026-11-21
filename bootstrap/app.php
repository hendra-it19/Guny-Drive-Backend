<?php

use App\Exceptions\ApiErrorException;
use App\Http\Middleware\ApiRequestMetrics;
use App\Http\Middleware\AuthenticateApiCredential;
use App\Http\Middleware\EnsureVaultSession;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (PHP_SAPI === 'cli-server' && is_file(dirname(__DIR__).'/.env')) {
    Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'dashboard_theme']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'api.metrics' => ApiRequestMetrics::class,
            'api.token' => AuthenticateApiCredential::class,
            'vault.session' => EnsureVaultSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiErrorException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');
            $rid = is_string($requestId) ? $requestId : Str::uuid()->toString();

            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'request_id' => $rid,
                ...($e->meta !== [] ? ['details' => $e->meta] : []),
            ], $e->statusCode)->header('X-Request-Id', $rid);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');
            $rid = is_string($requestId) ? $requestId : Str::uuid()->toString();

            return response()->json([
                'code' => 'validation_failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'request_id' => $rid,
            ], 422)->header('X-Request-Id', $rid);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');
            $rid = is_string($requestId) ? $requestId : Str::uuid()->toString();

            return response()->json([
                'code' => 'not_found',
                'message' => 'Sumber daya tidak ditemukan.',
                'request_id' => $rid,
            ], 404)->header('X-Request-Id', $rid);
        });
    })->create();

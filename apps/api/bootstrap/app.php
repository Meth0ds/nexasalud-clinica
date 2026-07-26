<?php

use App\Exceptions\ProblemDetailsFactory;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->preventRequestForgery();
        $middleware->alias([
            'secure.session' => \App\Http\Middleware\EnforceAbsoluteSessionLifetime::class,
            'tenant.context' => \App\Http\Middleware\ResolveTenantContext::class,
            'idempotent' => \App\Http\Middleware\EnforceIdempotency::class,
        ]);
        $middleware->append([
            AssignTraceId::class,
            PreventSensitiveResponseCaching::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') || $request->is('auth/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        $exceptions->dontReport([
            \DomainException::class,
        ]);
        $exceptions->dontFlash([
            'access_token',
            'authorization',
            'code',
            'cookie',
            'id_token',
            'password',
            'password_confirmation',
            'refresh_token',
            'state',
            'token',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->is('auth/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('auth/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ProblemDetailsFactory::class)->response($exception, $request);
        });
    })->create();

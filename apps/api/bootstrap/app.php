<?php

use App\Exceptions\ProblemDetailsFactory;
use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/ready', ReadinessController::class);
            Route::middleware('web')->group(base_path('routes/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: fn (): array => config('app.trusted_hosts', []),
            subdomains: false,
        );

        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->statefulApi();
        $middleware->preventRequestForgery();
        $middleware->alias([
            'secure.session' => EnforceAbsoluteSessionLifetime::class,
            'tenant.context' => ResolveTenantContext::class,
            'idempotent' => EnforceIdempotency::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenantContext::class,
        );
        $middleware->prependToPriorityList(
            ResolveTenantContext::class,
            EnsureUserIsActive::class,
        );
        $middleware->prependToPriorityList(
            EnsureUserIsActive::class,
            EnforceAbsoluteSessionLifetime::class,
        );
        $middleware->append([
            AssignTraceId::class,
            PreventSensitiveResponseCaching::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') || $request->is('auth/*') ? null : '/',
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('waitlist:expire-offers')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('waitlist:match-slots')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->environments(['local', 'staging', 'production']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        $exceptions->dontReport([
            DomainException::class,
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

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->is('auth/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ProblemDetailsFactory::class)->response($exception, $request);
        });
    })->create();

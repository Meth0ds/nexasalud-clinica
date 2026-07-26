<?php

use App\Http\Controllers\Auth\AuthSessionController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\OidcCallbackController;
use App\Http\Controllers\Auth\SelectTenantContextController;
use App\Http\Controllers\Auth\StartOidcLoginController;
use App\Http\Controllers\Auth\TenantContextOptionsController;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::get('/login', StartOidcLoginController::class)
        ->middleware('throttle:oidc-login')
        ->block(60, 5)
        ->name('login');
    Route::get('/callback', OidcCallbackController::class)
        ->middleware('throttle:oidc-callback')
        ->block(60, 5)
        ->name('callback');
    Route::post('/logout', LogoutController::class)
        ->middleware(['auth:web', 'throttle:logout'])
        ->block(10, 5)
        ->name('logout');
});

Route::get('/api/session', AuthSessionController::class)
    ->middleware(['auth:web', EnforceAbsoluteSessionLifetime::class, EnsureUserIsActive::class, 'tenant.context', 'throttle:auth-session'])
    ->block(10, 5)
    ->name('api.session');

Route::get('/api/session/context-options', TenantContextOptionsController::class)
    ->middleware(['auth:web', EnforceAbsoluteSessionLifetime::class, EnsureUserIsActive::class, 'throttle:auth-session'])
    ->block(10, 5)
    ->name('api.session.context_options');

Route::post('/api/session/context', SelectTenantContextController::class)
    ->middleware(['auth:web', EnforceAbsoluteSessionLifetime::class, EnsureUserIsActive::class, 'throttle:auth-session'])
    ->block(10, 5)
    ->name('api.session.context_select');

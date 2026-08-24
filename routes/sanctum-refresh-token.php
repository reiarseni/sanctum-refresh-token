<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\RefreshTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Refresh token routes
|--------------------------------------------------------------------------
|
| Published, not registered. The package never mounts a token endpoint on
| your application by surprise: you publish this file with
|
|   php artisan vendor:publish --tag=sanctum-refresh-token-routes
|
| and then require it from your own bootstrap/app.php or RouteServiceProvider.
| It is yours from that point on — change the paths, the middleware, the
| throttles and the response shapes to suit the API you are building.
|
| Rate limiting is the one thing worth keeping. `login` is a credential
| endpoint and `refresh` is the endpoint an attacker holding a stolen token
| would hammer.
|
*/

Route::prefix('auth')->group(function (): void {
    Route::post('login', [RefreshTokenController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('auth.login');

    Route::post('refresh', [RefreshTokenController::class, 'refresh'])
        ->middleware('throttle:30,1')
        ->name('auth.refresh');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [RefreshTokenController::class, 'logout'])->name('auth.logout');
        Route::get('sessions', [RefreshTokenController::class, 'sessions'])->name('auth.sessions');
    });
});

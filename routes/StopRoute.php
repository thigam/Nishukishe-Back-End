<?php
use App\Http\Controllers\StopsController;
use App\Http\Controllers\DirectionsController;
use  Illuminate\Support\Facades\Route;
use App\Models\Stops;
use Illuminate\Http\JsonResponse;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Kernel;
use App\Http\Middleware\RoleMiddleware;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\LogUserActivity;

// RoleMiddleware::class
Route::prefix('stops')->controller(StopsController::class)->middleware([CorsMiddleware::class, LogUserActivity::class])->group(function () {
    Route::get('/', 'index')->middleware(['auth:sanctum', RoleMiddleware::class])->name('stops.index');

    Route::middleware('throttle:public-sacco-api')->group(function () {
        Route::get('/search/{letters}', 'showByLetters')->name('stops.search');
        Route::get('/nearby', 'nearby')->name('stops.nearby');
    });
});


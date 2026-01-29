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
Route::prefix('stops')->controller(StopsController::class)->middleware(CorsMiddleware::class,LogUserActivity::class,RoleMiddleware::class)->group(function () {
    Route::get('/', 'index')->name('stops.index');
    Route::get('/search/{letters}', 'showByLetters')->name('stops.search');
    Route::get('/nearby', 'nearby')->name('stops.nearby');
});


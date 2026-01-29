<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RouteController;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\CorsMiddleware;

Route::middleware([CorsMiddleware::class,RoleMiddleware::class, LogUserActivity::class])->group(function () {
    Route::get('/routes', [RouteController::class, 'index'])->name('saccoroutes.index');
    Route::post('/routes', [RouteController::class, 'store'])
        ->middleware([ RoleMiddleware::class])
        ->name('routes.store');
});


<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VariationController;
use App\Http\Middleware\CorsMiddleware;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\LogUserActivity;

Route::prefix('variations')->controller(VariationController::class)
    ->middleware(CorsMiddleware::class,RoleMiddleware::class,LogUserActivity::class)
    ->group(function () {
        Route::post('/', 'store')->name('variations.store');
    });

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductWaitlistController;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\LogUserActivity;

Route::middleware([CorsMiddleware::class, LogUserActivity::class, 'auth:sanctum'])->group(function () {
    // Waitlist System
    Route::post('waitlist', [ProductWaitlistController::class, 'store']);
    Route::get('admin/waitlist', [ProductWaitlistController::class, 'index'])
        ->middleware(\App\Http\Middleware\RoleMiddleware::class . ':super_admin');
});

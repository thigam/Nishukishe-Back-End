<?php

use App\Http\Controllers\DirectionsController;
use App\Models\Directions;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\LogUserActivity;
use App\Http\Kernel;
use Jenssegers\Agent\Agent;

Route::prefix('/direction')->controller(DirectionsController::class)->middleware([RoleMiddleware::class,CorsMiddleware::class,LogUserActivity::class])->group(function () {
    Route::get('/', 'index')->name('direction.index');
    Route::get('/{$heading}','show')->name('direction.show');
    Route::get('/routes/{route}','showRoutes')->name('direction.routes'); 
    Route::get('/ending/{ending}','showByEnding')->name('direction.ending');
    Route::get('/search/{end_lat}/{end_long}','searchByStartEndGet')->name('direction.search');
    Route::post('/findStops','findStops')->name('direction.find');
});
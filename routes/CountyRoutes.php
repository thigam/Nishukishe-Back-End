<?php

use Illuminate\Support\Facades\Route;
use App\Models\Counties;
use App\Http\Controllers\CountiesController;
use Illuminate\Http\JsonResponse;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\RoleMiddleware;


Route::prefix('/counties')->controller(CountiesController::class)->middleware([CorsMiddleware::class,LogUserActivity::class,RoleMiddleware::class])->group(function () {
    Route::get('/', 'index')->name('counties.index');
    Route::get('/{county_id}', 'find')->name('counties.show');
});
?>
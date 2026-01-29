<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Controllers\PostCleanPublishController;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\LogUserActivity;
use App\Http\Controllers\PostCleanSaccoRouteController;
use App\Http\Controllers\PostCleanVariationController;
use App\Http\Controllers\PostCleanTripController;

Route::prefix('post-clean')
  ->middleware([CorsMiddleware::class, RoleMiddleware::class, 'auth:sanctum',LogUserActivity::class])
  ->group(function () {
      Route::post('publish-all', [PostCleanPublishController::class, 'publishAll'])
              ->name('post-clean.publish-all');
      Route::post('publish', [PostCleanPublishController::class, 'publishSelected'])
              ->name('post-clean.publish');
      Route::get('publish-summary', [PostCleanPublishController::class, 'summary'])
     ->name('post-clean.summary');

     Route::get('routes',[PostCleanSaccoRouteController::class, 'index'])
         ->name('post-clean.routes.index');

    Route::get('routes/{id}', [PostCleanSaccoRouteController::class, 'show'])
        ->name('post-clean.routes.show');
    Route::get('variations/{id}',[PostCleanVariationController::class, 'show'])
        ->name('post-clean.variations.index');

    Route::get('trips/{id}', [PostCleanTripController::class, 'show'])
        ->name('post-clean.trips.show');

  });




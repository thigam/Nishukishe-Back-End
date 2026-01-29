<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Controllers\PreCleanSaccoRouteController;
use App\Http\Controllers\PreCleanStopController;
use App\Http\Controllers\PreCleanVariationController;
use App\Http\Controllers\PreCleanTripController;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\LogUserActivity;

// Add auth:sanctum so $request->user() + token abilities work
Route::prefix('pre-clean')->middleware([CorsMiddleware::class, RoleMiddleware::class,'auth:sanctum', LogUserActivity::class])->group(function () {
    Route::controller(PreCleanSaccoRouteController::class)->group(function () {
        Route::get('routes/duplicate-check', 'checkDuplicatePair')->name('pre-clean.routes.duplicate-check');
        Route::get('routes', 'index')->name('pre-clean.routes.index');
        Route::post('routes', 'store')->name('pre-clean.routes.store');
        Route::get('routes/{id}', 'show')->name('pre-clean.routes.show');
        Route::put('routes/{id}', 'update')->name('pre-clean.routes.update');
        Route::delete('routes/{id}', 'destroy')->name('pre-clean.routes.destroy');
        Route::post('routes/{id}/approve', 'approve')->name('pre-clean.routes.approve');
        Route::post('routes/{id}/reject', 'reject')->name('pre-clean.routes.reject');

        // ✅ used by the cleaning page to promote everything to post-clean
        Route::post('routes/{id}/finalize', 'finalize')->name('pre-clean.routes.finalize');

        // ✅ delete the pre-clean route + its stops, trips, variations
        Route::delete('routes/{id}/deep', 'destroyDeep')->name('pre-clean.routes.destroyDeep');

        // already used by the page to fetch everything needed
        Route::get('routes/{id}/with-stops', 'showWithStops')->name('pre-clean.routes.with-stops');
    });

    Route::controller(PreCleanStopController::class)->middleware([CorsMiddleware::class, 'auth:sanctum', RoleMiddleware::class, LogUserActivity::class])->group(function () {
        Route::get('stops', 'index')->name('pre-clean.stops.index');
        Route::post('stops','store')->name('pre-clean.stops.store');
        Route::get('stops/{id}','show')->name('pre-clean.stops.show');
        Route::put('stops/{id}','update')->name('pre-clean.stops.update');
        Route::delete('stops/{id}','destroy')->name('pre-clean.stops.destroy');
        Route::post('stops/{id}/approve','approve')->name('pre-clean.stops.approve');
        Route::post('stops/{id}/reject','reject')->name('pre-clean.stops.reject');
    });

    Route::controller(PreCleanVariationController::class)->middleware([CorsMiddleware::class, 'auth:sanctum', RoleMiddleware::class, LogUserActivity::class])->group(function () {
        Route::get('variations/{id}', 'index')->name('pre-clean.variations.index');
        Route::post('variations', 'store')->name('pre-clean.variations.store');
        Route::post('variations/{id}/approve', 'approve')->name('pre-clean.variations.approve');
        Route::post('variations/{id}/reject', 'reject')->name('pre-clean.variations.reject');
    });

    Route::controller(PreCleanTripController::class)->middleware([CorsMiddleware::class, 'auth:sanctum', RoleMiddleware::class, LogUserActivity::class])->group(function () {
        Route::post('trips', 'store')->name('pre-clean.trips.store');
        Route::get('trips/{routeId}', 'index')->name('pre-clean.trips.index');
    });
});


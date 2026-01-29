<?php

use App\Http\Controllers\SaccoController;
use App\Http\Controllers\SaccoRoutesController;
use App\Http\Controllers\SaccoStageController;
use App\Models\Sacco;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\LogUserActivity;
use Jenssegers\Agent\Agent;

use App\Http\Kernel;

Route::prefix('routes')->controller(SaccoRoutesController::class)->middleware([CorsMiddleware::class, RoleMiddleware::class, LogUserActivity::class])->group(function () {
    Route::get('/',  'index')->name('saccoroutes.index');
    Route::get('/id/{id}','showByRoute')->name('sacco.show');
    Route::get('/sacco/{sacco}','showBySacco')->name('sacco.sacco');
    Route::get('/{id}/directions', 'directions')->name('routes.directions');
    Route::get('/{id}/verification', 'showVerification')->name('routes.verification');
    Route::post('/{id}/request-cleanup', 'requestCleanup')->name('routes.request-cleanup');
    Route::post('/{id}/verify', 'verifyRoute')->name('routes.verify');
    Route::get('/stop/{stop}', 'showByStop')->name('sacco.stop');
    Route::post('/add', 'addNewSaccoRoute')->name('addNewSaccoRoute');
    Route::put('/update/{id}',  'update')->name('sacco.update');
    Route::delete('/delete/{id}', 'destroy')->name('sacco.delete');
    Route::get('/search', 'searchByCoordinates');
});

Route::prefix('sacco')->controller(SaccoController::class)->middleware([CorsMiddleware::class, RoleMiddleware::class, LogUserActivity::class])->group(function () {
    Route::get('/', 'index')->name('sacco.index');
    Route::get('/{id}', 'findById')->name('sacco.findById');
    Route::get('/name/{name}','findByName')->name('sacco.findByName');
    Route::post('/create', 'saccoRegister')->name('sacco.create');
    Route::post('/placeholder', 'createPlaceholderSacco')->name('sacco.placeholder');
    Route::get('/phone/{phone}', 'findByPhone')->name('sacco.findByPhone');
    Route::get('/email/{email}', 'findByEmail')->name('sacco.findByEmail');
    Route::get('/location/{location}', 'findByLocation')->name('sacco.findByLocation');
    Route::get('/{id}/approved-people', 'approvedPeople');
    Route::get('/{id}/vehicles', 'vehicles');
    Route::post('/safari', 'createSafari')->name('sacco.createSafari');
    Route::get('/safari/{id}', 'getSafari')->name('sacco.getSafari');
    Route::post('/safari/{id}/book', 'bookSafariSeat')->name('sacco.bookSafariSeat');
});

Route::prefix('sacco/{sacco}/stages')
    ->controller(SaccoStageController::class)
    ->middleware([CorsMiddleware::class, LogUserActivity::class])
    ->group(function () {
        Route::get('/', 'index')->name('sacco.stages.index');
        Route::get('/{stage}', 'show')->name('sacco.stages.show');
    });

Route::prefix('sacco/{sacco}/stages')
    ->controller(SaccoStageController::class)
    ->middleware([CorsMiddleware::class, 'auth:sanctum', RoleMiddleware::class, LogUserActivity::class])
    ->group(function () {
        Route::post('/', 'store')->name('sacco.stages.store');
        Route::put('/{stage}', 'update')->name('sacco.stages.update');
        Route::delete('/{stage}', 'destroy')->name('sacco.stages.destroy');
    });

Route::prefix('sacco')
    ->controller(SaccoController::class)
    ->middleware([CorsMiddleware::class, 'auth:sanctum', RoleMiddleware::class, LogUserActivity::class])
    ->group(function () {
        Route::put('/{id}/profile', 'updateProfile')->name('sacco.updateProfile');
    });

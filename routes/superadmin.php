<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SuperAdmin\SocialMetricController;
use App\Http\Controllers\SuperAdmin\TembeaBookingReportController;
use App\Http\Controllers\SuperAdmin\TembeaPayoutController;
use App\Http\Controllers\SuperAdminAnalyticsController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Jenssegers\Agent\Agent;
use App\Http\Middleware\LogUserActivity;

Route::prefix('superadmin')
    ->middleware(['auth:sanctum', CorsMiddleware::class, RoleMiddleware::class, LogUserActivity::class])
    ->group(function () {
        Route::controller(SuperAdminController::class)->group(function () {
            Route::get('/users', 'users')->name('superadmin.users');
            Route::put('/users/{id}', 'updateUser')->name('superadmin.users.update');
            Route::delete('/users/{id}', 'deleteUser')->name('superadmin.users.delete');
            Route::get('/logs', 'logs')->name('superadmin.logs');

            Route::get('/saccos', 'saccos')->name('superadmin.saccos');
            Route::put('/saccos/{id}', 'updateSacco')->name('superadmin.saccos.update');
            Route::delete('/saccos/{id}', 'deleteSacco')->name('superadmin.saccos.delete');
            Route::post('/saccos/{id}/approve', 'approveSacco')->name('superadmin.saccos.approve');

            Route::get('/sacco-routes', 'saccoRoutes')->name('superadmin.saccoroutes');
            Route::put('/sacco-routes/{id}', 'updateSaccoRoute')->name('superadmin.saccoroutes.update');
            Route::delete('/sacco-routes/{id}', 'deleteSaccoRoute')->name('superadmin.saccoroutes.delete');

            Route::get('/routes', 'routes')->name('superadmin.routes');
            Route::put('/routes/{id}', 'updateRoute')->name('superadmin.routes.update');
            Route::delete('/routes/{id}', 'deleteRoute')->name('superadmin.routes.delete');

            Route::get('/counties', 'counties')->name('superadmin.counties');
            Route::put('/counties/{id}', 'updateCounty')->name('superadmin.counties.update');
            Route::delete('/counties/{id}', 'deleteCounty')->name('superadmin.counties.delete');

            Route::get('/unverified-admins', 'unverifiedAdmins')->name('superadmin.unverified-admins');
            Route::post('/unverified-admins/{id}/verify', 'verifyAdmin')->name('superadmin.unverified-admins.verify');
            Route::post('/impersonate', 'impersonate')->name('superadmin.impersonate');
        });

        Route::controller(SuperAdminAnalyticsController::class)->group(function () {
            Route::get('/analytics', 'index')->name('superadmin.analytics');
        });

        Route::controller(SocialMetricController::class)->group(function () {
            Route::get('/social-metrics', 'index')->name('superadmin.social-metrics.index');
            Route::post('/social-metrics', 'store')->name('superadmin.social-metrics.store');
            Route::put('/social-metrics/{socialMetric}', 'update')->name('superadmin.social-metrics.update');
        });

        Route::get('/bookings', [TembeaBookingReportController::class, 'index'])->name('superadmin.bookings.index');

        Route::controller(TembeaPayoutController::class)->group(function () {
            Route::get('/tembea-payouts', 'index')->name('superadmin.tembea-payouts.index');
            Route::post('/tembea-payouts/build', 'build')->name('superadmin.tembea-payouts.build');
            Route::post('/tembea-payouts/{settlement}/initiate', 'initiate')->name('superadmin.tembea-payouts.initiate');
            Route::post('/tembea-payouts/{settlement}/finalize', 'finalize')->name('superadmin.tembea-payouts.finalize');
        });

        Route::controller(\App\Http\Controllers\SuperAdmin\ScalpingController::class)->group(function () {
            Route::post('/scalping/search', 'search')->name('superadmin.scalping.search');
            Route::post('/scalping/approve', 'approve')->name('superadmin.scalping.approve');
        });
    });

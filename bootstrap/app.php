<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('emails:send-daily-summary')->dailyAt('23:59');
        $schedule->command('app:send-incident-notifications')->everyTenMinutes();
        $schedule->command('app:send-scheduled-notifications')->everyMinute();
        $schedule->command('notifications:send-onboarding-tips')->cron('*/25 * * * *');
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'cors' => \App\Http\Middleware\CorsMiddleware::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'verify.tier' => \App\Http\Middleware\VerifySaccoTier::class,
            'abilities' => checkAbilities::class,
            'ability' => checkAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

<?php

namespace App\Console;

use App\Console\Commands\RunAutomatedTests;
use App\Console\Commands\SeedRouteFlags;
use App\Console\Commands\SocialIngestCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        SeedRouteFlags::class,
        \App\Console\Commands\BackfillReverseSaccoRoutes::class,
        \App\Console\Commands\BuildCorridorData::class,
        RunAutomatedTests::class,
        SocialIngestCommand::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('tests:run')->dailyAt('00:00');
        $schedule->command('backup:run --only-db')->weeklyOn(1, '02:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

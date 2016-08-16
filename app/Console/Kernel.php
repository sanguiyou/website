<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

date_default_timezone_set("PRC");

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // Commands\Inspire::class,
        Commands\Sync::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')
        //          ->hourly();

        echo date("Y-m-d H:i:s")."\n";
        $schedule->command('flow:sync 0 ' . date('Y-m-d'))->hourly();
        $schedule->command('flow:sync 1 ' . date('Y-m-d'))->hourly();
        $schedule->command('flow:sync 0 ' . date('Y-m-d', time() - (60 * 60 * 24) ))->dailyAt('00:20');
        $schedule->command('flow:sync 1 ' . date('Y-m-d', time() - (60 * 60 * 24) ))->dailyAt('00:40');
    }
}

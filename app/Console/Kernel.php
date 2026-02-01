<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * These scheduled tasks are called based on the run frequency set.
     * Make sure cron is configured: * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
     */
    protected function schedule(Schedule $schedule): void
    {
        /*
        |--------------------------------------------------------------------------
        | Notification Schedules
        |--------------------------------------------------------------------------
        */

        // Process 2-hourly batched notifications
        $schedule->command('nearbuy:send-batched-notifications')
            ->everyTwoHours()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/notifications.log'));

        // Process 9 AM scheduled notifications (daily + twice_daily)
        $schedule->command('nearbuy:send-scheduled-notifications')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/notifications.log'));

        // Process 5 PM scheduled notifications (twice_daily)
        $schedule->command('nearbuy:send-scheduled-notifications')
            ->dailyAt('17:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/notifications.log'));

        /*
        |--------------------------------------------------------------------------
        | Expiration Schedules
        |--------------------------------------------------------------------------
        */

        // Expire offers - run hourly
        $schedule->command('nearbuy:expire-offers --notify')
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/expiry.log'));

        // Expire product requests - run every 30 minutes
        $schedule->command('nearbuy:expire-product-requests --notify')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/expiry.log'));

        // Expire pending agreements - run daily at midnight
        $schedule->command('nearbuy:expire-agreements')
            ->dailyAt('00:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/expiry.log'));

        // Expire fish catches - run every 15 minutes
        $schedule->command('fish:expire-catches')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/expiry.log'));

        /*
        |--------------------------------------------------------------------------
        | Jobs Module Schedules (Njaanum Panikkar)
        |--------------------------------------------------------------------------
        */

        // Expire open jobs past their expiration time - run every 5 minutes
        $schedule->command('jobs:expire --notify')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/jobs.log'));

        // Send job reminders to workers and posters - run every 15 minutes
        $schedule->command('jobs:send-reminders')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/jobs.log'));

        // Calculate weekly earnings - run Sunday at midnight
        $schedule->command('jobs:calculate-earnings --notify')
            ->weeklyOn(0, '00:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/jobs.log'));

        // Check and award badges - run daily at midnight
        $schedule->command('jobs:check-badges --notify')
            ->dailyAt('00:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/jobs.log'));

        /*
        |--------------------------------------------------------------------------
        | Cleanup Schedules
        |--------------------------------------------------------------------------
        */

        // Clean up old data - run daily at 3 AM
        $schedule->command('nearbuy:cleanup-old-data --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/cleanup.log'));

        /*
        |--------------------------------------------------------------------------
        | Queue Health
        |--------------------------------------------------------------------------
        */

        // Prune failed jobs older than 7 days
        $schedule->command('queue:prune-failed --hours=168')
            ->daily()
            ->appendOutputTo(storage_path('logs/queue.log'));

        // Prune batches older than 48 hours
        $schedule->command('queue:prune-batches --hours=48')
            ->daily()
            ->appendOutputTo(storage_path('logs/queue.log'));

        // Restart queue workers to pick up code changes (optional, use with caution)
        // $schedule->command('queue:restart')->hourly();

        /*
        |--------------------------------------------------------------------------
        | Monitoring (Optional)
        |--------------------------------------------------------------------------
        */

        // Health check - ping external monitoring service
        // $schedule->command('inspire')
        //     ->everyFiveMinutes()
        //     ->pingOnSuccess(env('HEALTH_PING_URL'));
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
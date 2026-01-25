<?php

/**
 * Fish Module - Kernel Schedule Configuration
 *
 * Add these schedule entries to your app/Console/Kernel.php schedule() method.
 *
 * IMPORTANT: This file is NOT meant to be used directly. Copy the schedule
 * entries below into your existing Kernel.php file.
 *
 * @srs-ref Pacha Meen Module - Scheduled Tasks
 */

// =============================================================================
// ADD THE FOLLOWING TO YOUR app/Console/Kernel.php IN THE schedule() METHOD
// =============================================================================

/*

    // =========================================================================
    // FISH MODULE SCHEDULED TASKS
    // =========================================================================

    // Process immediate fish alerts every minute
    // These are alerts for subscribers who want instant notifications
    $schedule->command('fish:process-alerts --immediate')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // Process hourly batch digests
    // Sends consolidated alerts to subscribers who chose hourly frequency
    $schedule->command('fish:process-alerts --hourly')
        ->hourly()
        ->at('05') // Run at :05 past the hour
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // Process daily batch digests
    // Sends consolidated alerts to subscribers who chose daily frequency
    // Runs at 6:00 AM IST (early morning when fish arrives)
    $schedule->command('fish:process-alerts --daily')
        ->dailyAt('06:00')
        ->timezone('Asia/Kolkata')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // Expire stale catches every 30 minutes
    // Marks catches as expired if they've passed their expiry time
    $schedule->command('fish:expire-catches')
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-maintenance.log'));

    // Cleanup old alerts weekly
    // Removes alerts older than 30 days to keep database clean
    $schedule->command('fish:cleanup-alerts --days=30')
        ->weekly()
        ->sundays()
        ->at('03:00')
        ->appendOutputTo(storage_path('logs/fish-maintenance.log'));

    // Generate daily fish stats report (optional)
    // Logs daily statistics for monitoring
    $schedule->command('fish:stats --period=today --json')
        ->dailyAt('23:55')
        ->appendOutputTo(storage_path('logs/fish-stats.log'));

*/

// =============================================================================
// ALTERNATIVE: USING JOBS DIRECTLY
// =============================================================================

/*
If you prefer to dispatch jobs directly instead of using commands:

use App\Jobs\Fish\ProcessFishAlertBatchJob;
use App\Jobs\Fish\ExpireStaleCatchesJob;
use App\Enums\FishAlertFrequency;

    // Process hourly batches
    $schedule->job(new ProcessFishAlertBatchJob(FishAlertFrequency::HOURLY))
        ->hourly()
        ->at('05');

    // Process daily batches
    $schedule->job(new ProcessFishAlertBatchJob(FishAlertFrequency::DAILY))
        ->dailyAt('06:00')
        ->timezone('Asia/Kolkata');

    // Expire stale catches
    $schedule->job(new ExpireStaleCatchesJob())
        ->everyThirtyMinutes();
*/

// =============================================================================
// QUEUE CONFIGURATION NOTES
// =============================================================================

/*
For optimal performance, configure separate queues for fish alerts:

1. In config/queue.php, ensure you have a 'fish-alerts' queue defined

2. In your queue worker, run:
   php artisan queue:work --queue=fish-alerts,default

3. For production with Supervisor, add this to supervisord.conf:

   [program:fish-alerts-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /path/to/artisan queue:work --queue=fish-alerts --sleep=3 --tries=3
   autostart=true
   autorestart=true
   numprocs=2
   user=www-data
   redirect_stderr=true
   stdout_logfile=/var/log/supervisor/fish-alerts.log

4. Jobs should specify their queue in the constructor:
   
   public function __construct()
   {
       $this->onQueue('fish-alerts');
   }
*/

// =============================================================================
// MONITORING RECOMMENDATIONS
// =============================================================================

/*
1. Set up alerts for:
   - Failed alert jobs > 10 per hour
   - Pending alerts > 100
   - Alert delivery rate < 90%

2. Log file rotation (add to /etc/logrotate.d/nearbuy-fish):

   /path/to/storage/logs/fish-*.log {
       daily
       missingok
       rotate 14
       compress
       delaycompress
       notifempty
       create 640 www-data www-data
   }

3. Health check endpoint (add to routes):

   Route::get('/health/fish-alerts', function () {
       $pending = \App\Models\FishAlert::whereNull('sent_at')->count();
       $failed = \App\Models\FishAlert::whereNotNull('failed_at')
           ->where('created_at', '>=', now()->subHour())
           ->count();
       
       return response()->json([
           'healthy' => $pending < 100 && $failed < 10,
           'pending' => $pending,
           'recent_failures' => $failed,
       ]);
   });
*/

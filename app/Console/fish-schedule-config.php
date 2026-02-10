<?php

/**
 * Fish Module - Kernel Schedule Configuration
 *
 * USAGE: Copy these entries to your app/Console/Kernel.php schedule() method.
 *
 * @srs-ref PM-014: Alert time preferences (Early Morning 5-7, Morning 7-9, Anytime)
 * @srs-ref PM-020: Respect alert time preferences
 * @srs-ref PM-024: Auto-expire after 6 hours
 */

// =============================================================================
// ADD TO app/Console/Kernel.php IN THE schedule() METHOD
// =============================================================================

/*

protected function schedule(Schedule $schedule): void
{
    // =========================================================================
    // 🐟 FISH MODULE SCHEDULED TASKS
    // =========================================================================

    // -------------------------------------------------------------------------
    // 1. IMMEDIATE ALERTS - Every minute (highest priority!)
    //    Fish alerts are time-sensitive. Target: deliver within 2 minutes.
    //    For subscribers who chose "Anytime" alerts.
    // -------------------------------------------------------------------------
    $schedule->command('fish:process-alerts --immediate')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // -------------------------------------------------------------------------
    // 2. EARLY MORNING BATCH - 5:00 AM IST
    //    @srs-ref PM-014: Early Morning (5-7 AM)
    //    For subscribers who want alerts before the market rush.
    // -------------------------------------------------------------------------
    $schedule->command('fish:process-alerts --early-morning')
        ->dailyAt('05:00')
        ->timezone('Asia/Kolkata')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // -------------------------------------------------------------------------
    // 3. MORNING BATCH - 7:00 AM IST
    //    @srs-ref PM-014: Morning (7-9 AM)
    //    For subscribers who want morning digest alerts.
    // -------------------------------------------------------------------------
    $schedule->command('fish:process-alerts --morning')
        ->dailyAt('07:00')
        ->timezone('Asia/Kolkata')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // -------------------------------------------------------------------------
    // 4. TWICE DAILY BATCH - 6 AM & 4 PM IST
    //    For subscribers who want consolidated updates twice a day.
    // -------------------------------------------------------------------------
    $schedule->command('fish:process-alerts --twice-daily')
        ->twiceDaily(6, 16)
        ->timezone('Asia/Kolkata')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-alerts.log'));

    // -------------------------------------------------------------------------
    // 5. EXPIRE STALE CATCHES - Every 30 minutes
    //    @srs-ref PM-024: Auto-expire catches > 6 hours old
    //    Notifies sellers: "⏰ [Fish] auto-expired (6hrs). Post fresh!"
    // -------------------------------------------------------------------------
    $schedule->command('fish:expire-catches --notify')
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/fish-maintenance.log'));

    // -------------------------------------------------------------------------
    // 6. DAILY STATS - 8:00 AM IST
    //    Admin stats: catches posted, alerts sent, sold out %, top fish
    // -------------------------------------------------------------------------
    $schedule->command('fish:stats --period=today')
        ->dailyAt('08:00')
        ->timezone('Asia/Kolkata')
        ->appendOutputTo(storage_path('logs/fish-stats.log'));

    // -------------------------------------------------------------------------
    // 7. WEEKLY CLEANUP - Sunday 3:00 AM
    //    Remove old alerts (30+ days) to keep database lean.
    // -------------------------------------------------------------------------
    $schedule->command('fish:cleanup-alerts --days=30')
        ->weeklyOn(0, '03:00') // Sunday 3 AM
        ->timezone('Asia/Kolkata')
        ->appendOutputTo(storage_path('logs/fish-maintenance.log'));
}

*/

// =============================================================================
// ALTERNATIVE: USING JOBS DIRECTLY
// =============================================================================

/*
use App\Jobs\Fish\ProcessFishAlertBatchJob;
use App\Jobs\Fish\ExpireStaleCatchesJob;
use App\Enums\FishAlertFrequency;

protected function schedule(Schedule $schedule): void
{
    // Early morning batch (5 AM) - PM-014
    $schedule->job(new ProcessFishAlertBatchJob(FishAlertFrequency::EARLY_MORNING))
        ->dailyAt('05:00')
        ->timezone('Asia/Kolkata');

    // Morning batch (7 AM) - PM-014
    $schedule->job(new ProcessFishAlertBatchJob(FishAlertFrequency::MORNING))
        ->dailyAt('07:00')
        ->timezone('Asia/Kolkata');

    // Twice daily batch (6 AM & 4 PM)
    $schedule->job(new ProcessFishAlertBatchJob(FishAlertFrequency::TWICE_DAILY))
        ->twiceDaily(6, 16)
        ->timezone('Asia/Kolkata');

    // Expire stale catches - PM-024
    $schedule->job(new ExpireStaleCatchesJob(hours: 6, notifySellers: true))
        ->everyThirtyMinutes();
}
*/

// =============================================================================
// QUEUE CONFIGURATION
// =============================================================================

/*
1. Add to config/queue.php connections:

'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => null,
],

2. Run dedicated fish alerts worker (time-sensitive!):

php artisan queue:work redis --queue=fish-alerts,default --tries=3 --backoff=10 --sleep=1

3. Supervisor config (/etc/supervisor/conf.d/fish-alerts.conf):

[program:fish-alerts]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/nearbuy/artisan queue:work redis --queue=fish-alerts --sleep=1 --tries=3 --backoff=10,30
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/fish-alerts.log
stopwaitsecs=60

4. Reload supervisor:

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start fish-alerts:*
*/

// =============================================================================
// SCHEDULE SUMMARY TABLE
// =============================================================================

/*
| Task                  | Schedule       | Time (IST)     | Command/Job                           | SRS Ref  |
|-----------------------|----------------|----------------|---------------------------------------|----------|
| Immediate alerts      | Every minute   | *              | fish:process-alerts --immediate       | PM-016   |
| Early morning batch   | Daily          | 05:00          | fish:process-alerts --early-morning   | PM-014   |
| Morning batch         | Daily          | 07:00          | fish:process-alerts --morning         | PM-014   |
| Twice daily batch     | Twice daily    | 06:00, 16:00   | fish:process-alerts --twice-daily     | PM-014   |
| Expire stale catches  | Every 30 min   | *              | fish:expire-catches --notify          | PM-024   |
| Daily stats           | Daily          | 08:00          | fish:stats --period=today             | -        |
| Cleanup old alerts    | Weekly (Sun)   | 03:00          | fish:cleanup-alerts --days=30         | -        |
*/

// =============================================================================
// MONITORING & HEALTH CHECK
// =============================================================================

/*
Add to routes/api.php:

Route::get('/health/fish', function () {
    $pending = \App\Models\FishAlert::where('status', 'pending')->count();
    $recentFailures = \App\Models\FishAlert::where('status', 'failed')
        ->where('created_at', '>=', now()->subHour())
        ->count();
    $staleCount = \App\Models\FishCatch::whereIn('status', ['available', 'low_stock'])
        ->where('updated_at', '<', now()->subHours(6))
        ->count();
    $oldestPending = \App\Models\FishAlert::where('status', 'pending')
        ->oldest()
        ->first()?->created_at;

    // Healthy if: not too many pending, few failures, no stale catches
    $healthy = $pending < 100 && $recentFailures < 10 && $staleCount < 10;

    // Alert if oldest pending is > 5 minutes (target is 2 min delivery)
    $alertDelay = $oldestPending && $oldestPending->diffInMinutes(now()) > 5;

    return response()->json([
        'healthy' => $healthy && !$alertDelay,
        'pending_alerts' => $pending,
        'oldest_pending_mins' => $oldestPending?->diffInMinutes(now()),
        'recent_failures' => $recentFailures,
        'stale_catches' => $staleCount,
        'checked_at' => now()->toIso8601String(),
    ], ($healthy && !$alertDelay) ? 200 : 503);
});
*/

// =============================================================================
// LOG ROTATION (/etc/logrotate.d/nearbuy-fish)
// =============================================================================

/*
/var/www/nearbuy/storage/logs/fish-*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 640 www-data www-data
    sharedscripts
    postrotate
        /usr/bin/supervisorctl restart fish-alerts:* > /dev/null 2>&1 || true
    endscript
}
*/
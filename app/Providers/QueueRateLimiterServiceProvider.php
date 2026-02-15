<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Queue Rate Limiter Service Provider.
 *
 * Configures rate limiters for WhatsApp API and other queue jobs.
 *
 * RATE LIMITS:
 * - whatsapp-api: 70 requests/second (WhatsApp allows ~80)
 *
 * Add to config/app.php providers array:
 * App\Providers\QueueRateLimiterServiceProvider::class
 *
 * @srs-ref NFR-P-01 - Respect API rate limits
 * @module Notifications
 */
class QueueRateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Configure queue rate limiters.
     */
    protected function configureRateLimiters(): void
    {
        /*
        |----------------------------------------------------------------------
        | WhatsApp API Rate Limiter
        |----------------------------------------------------------------------
        |
        | WhatsApp Business API allows approximately 80 messages per second.
        | We use 70/second as a conservative limit to avoid hitting rate limits.
        |
        | When limit is hit, job is released back to queue with 1 second delay.
        |
        */
        RateLimiter::for('whatsapp-api', function ($job) {
            return Limit::perSecond(70)
                ->by('global')
                ->response(function ($job, $headers) {
                    // Release job back to queue after 1 second
                    $job->release(1);
                });
        });

        /*
        |----------------------------------------------------------------------
        | Per-Phone Rate Limiter
        |----------------------------------------------------------------------
        |
        | Prevent flooding individual users with too many messages.
        | Max 10 messages per minute per phone number.
        |
        */
        RateLimiter::for('whatsapp-per-phone', function ($job) {
            $phone = $job->phone ?? 'unknown';

            return Limit::perMinute(10)
                ->by($phone)
                ->response(function ($job, $headers) {
                    // Release with longer delay (10 seconds)
                    $job->release(10);
                });
        });

        /*
        |----------------------------------------------------------------------
        | Flash Deal Burst Limiter
        |----------------------------------------------------------------------
        |
        | Flash Deals can trigger many notifications at once.
        | Allow burst of 500 in first 10 seconds, then throttle.
        |
        */
        RateLimiter::for('flash-deal-burst', function ($job) {
            return [
                Limit::perSecond(100)->by('flash-deals'),
                Limit::perMinute(2000)->by('flash-deals'),
            ];
        });

        /*
        |----------------------------------------------------------------------
        | Batch Processing Limiter
        |----------------------------------------------------------------------
        |
        | Limit concurrent batch processing to prevent overwhelming the system.
        | Max 50 batches processed per minute.
        |
        */
        RateLimiter::for('batch-processing', function ($job) {
            return Limit::perMinute(50)
                ->by('batches')
                ->response(function ($job, $headers) {
                    $job->release(5);
                });
        });
    }
}
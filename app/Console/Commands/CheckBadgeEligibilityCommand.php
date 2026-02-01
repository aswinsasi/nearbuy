<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\JobWorker;
use App\Models\WorkerBadge;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Check and award badges to eligible workers.
 *
 * This command evaluates all active workers for badge eligibility
 * and awards any newly earned badges.
 *
 * Badge Types:
 * - FIRST_JOB: Completed first job
 * - FIVE_STAR: Received a 5-star rating
 * - TEN_JOBS: Completed 10 jobs
 * - FIFTY_JOBS: Completed 50 jobs
 * - HUNDRED_JOBS: Completed 100 jobs
 * - TOP_RATED: Average rating >= 4.8 with 10+ ratings
 * - QUICK_RESPONDER: Responds to jobs within 5 minutes
 * - VETERAN: Active for 6+ months with 50+ jobs
 *
 * @example
 * php artisan jobs:check-badges
 * php artisan jobs:check-badges --notify
 * php artisan jobs:check-badges --dry-run
 * php artisan jobs:check-badges --worker=123
 *
 * @srs-ref Njaanum Panikkar Module - Worker Badges
 */
class CheckBadgeEligibilityCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:check-badges
                            {--worker= : Check specific worker ID only}
                            {--notify : Send notification for new badges}
                            {--dry-run : Show eligible badges without awarding}';

    /**
     * The console command description.
     */
    protected $description = 'Check and award badges to eligible workers';

    /**
     * Badge criteria definitions.
     */
    protected array $badgeCriteria = [
        'FIRST_JOB' => [
            'name' => 'First Job',
            'icon' => '🎯',
            'description' => 'Completed your first job',
            'check' => 'checkFirstJob',
        ],
        'FIVE_STAR' => [
            'name' => 'Five Star',
            'icon' => '⭐',
            'description' => 'Received a 5-star rating',
            'check' => 'checkFiveStar',
        ],
        'TEN_JOBS' => [
            'name' => '10 Jobs',
            'icon' => '🔟',
            'description' => 'Completed 10 jobs',
            'check' => 'checkTenJobs',
        ],
        'FIFTY_JOBS' => [
            'name' => '50 Jobs',
            'icon' => '5️⃣0️⃣',
            'description' => 'Completed 50 jobs',
            'check' => 'checkFiftyJobs',
        ],
        'HUNDRED_JOBS' => [
            'name' => '100 Jobs',
            'icon' => '💯',
            'description' => 'Completed 100 jobs',
            'check' => 'checkHundredJobs',
        ],
        'TOP_RATED' => [
            'name' => 'Top Rated',
            'icon' => '🏆',
            'description' => 'Average rating 4.8+ with 10+ ratings',
            'check' => 'checkTopRated',
        ],
        'QUICK_RESPONDER' => [
            'name' => 'Quick Responder',
            'icon' => '⚡',
            'description' => 'Responds to jobs quickly',
            'check' => 'checkQuickResponder',
        ],
        'VETERAN' => [
            'name' => 'Veteran',
            'icon' => '🎖️',
            'description' => 'Active for 6+ months with 50+ jobs',
            'check' => 'checkVeteran',
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        $this->info('Checking badge eligibility...');

        // Get workers to check
        $query = JobWorker::query()
            ->where('is_active', true)
            ->with(['user', 'badges']);

        if ($this->option('worker')) {
            $query->where('id', $this->option('worker'));
        }

        $workers = $query->get();

        if ($workers->isEmpty()) {
            $this->info('No workers to check.');
            return self::SUCCESS;
        }

        $this->info("Checking {$workers->count()} worker(s)...");

        $totalAwarded = 0;
        $workersAwarded = 0;

        foreach ($workers as $worker) {
            $newBadges = $this->checkWorkerBadges($worker);

            if (empty($newBadges)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->showWorkerBadges($worker, $newBadges);
                continue;
            }

            // Award badges
            $awarded = $this->awardBadges($worker, $newBadges);
            $totalAwarded += count($awarded);

            if (!empty($awarded)) {
                $workersAwarded++;

                // Send notification if requested
                if ($this->option('notify')) {
                    $this->sendBadgeNotification($whatsApp, $worker, $awarded);
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete - no badges were awarded.');
            return self::SUCCESS;
        }

        $this->info("✅ Awarded {$totalAwarded} badge(s) to {$workersAwarded} worker(s).");

        return self::SUCCESS;
    }

    /**
     * Check which badges a worker is eligible for.
     */
    protected function checkWorkerBadges(JobWorker $worker): array
    {
        $eligibleBadges = [];
        $existingBadges = $worker->badges->pluck('badge_type')->toArray();

        foreach ($this->badgeCriteria as $badgeType => $criteria) {
            // Skip if already has this badge
            if (in_array($badgeType, $existingBadges)) {
                continue;
            }

            // Check eligibility
            $checkMethod = $criteria['check'];
            if ($this->$checkMethod($worker)) {
                $eligibleBadges[] = $badgeType;
            }
        }

        return $eligibleBadges;
    }

    /**
     * Award badges to worker.
     */
    protected function awardBadges(JobWorker $worker, array $badges): array
    {
        $awarded = [];

        foreach ($badges as $badgeType) {
            try {
                WorkerBadge::create([
                    'worker_id' => $worker->id,
                    'badge_type' => $badgeType,
                    'awarded_at' => now(),
                ]);

                $awarded[] = $badgeType;

                Log::info('Badge awarded', [
                    'worker_id' => $worker->id,
                    'badge' => $badgeType,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to award badge', [
                    'worker_id' => $worker->id,
                    'badge' => $badgeType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $awarded;
    }

    /**
     * Send notification for new badges.
     */
    protected function sendBadgeNotification(WhatsAppService $whatsApp, JobWorker $worker, array $badges): void
    {
        if (!$worker->user || !$worker->user->phone) {
            return;
        }

        try {
            $badgeList = [];
            foreach ($badges as $badgeType) {
                $criteria = $this->badgeCriteria[$badgeType];
                $badgeList[] = "{$criteria['icon']} *{$criteria['name']}* - {$criteria['description']}";
            }

            $badgeText = implode("\n", $badgeList);
            $pluralS = count($badges) > 1 ? 's' : '';

            $message = "🎉 *New Badge{$pluralS} Earned!*\n" .
                "*പുതിയ ബാഡ്ജ് നേടി!*\n\n" .
                "Congratulations *{$worker->name}*!\n\n" .
                "{$badgeText}\n\n" .
                "Keep up the great work! 💪\n" .
                "ബാഡ്ജുകൾ നിങ്ങളുടെ പ്രൊഫൈലിൽ കാണിക്കും.";

            $whatsApp->sendButtons(
                $worker->user->phone,
                $message,
                [
                    ['id' => 'job_worker_menu', 'title' => '👷 My Profile'],
                    ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎉 Badge Earned!'
            );

        } catch (\Exception $e) {
            Log::error('Failed to send badge notification', [
                'worker_id' => $worker->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Show badges that would be awarded (dry run).
     */
    protected function showWorkerBadges(JobWorker $worker, array $badges): void
    {
        $badgeNames = array_map(function ($badge) {
            return $this->badgeCriteria[$badge]['icon'] . ' ' . $this->badgeCriteria[$badge]['name'];
        }, $badges);

        $this->info("Worker #{$worker->id} ({$worker->name}): " . implode(', ', $badgeNames));
    }

    /*
    |--------------------------------------------------------------------------
    | Badge Check Methods
    |--------------------------------------------------------------------------
    */

    protected function checkFirstJob(JobWorker $worker): bool
    {
        return $worker->jobs_completed >= 1;
    }

    protected function checkFiveStar(JobWorker $worker): bool
    {
        // Check if worker has received at least one 5-star rating
        return $worker->completedJobs()
            ->whereHas('verification', function ($q) {
                $q->where('worker_rating', 5);
            })
            ->exists();
    }

    protected function checkTenJobs(JobWorker $worker): bool
    {
        return $worker->jobs_completed >= 10;
    }

    protected function checkFiftyJobs(JobWorker $worker): bool
    {
        return $worker->jobs_completed >= 50;
    }

    protected function checkHundredJobs(JobWorker $worker): bool
    {
        return $worker->jobs_completed >= 100;
    }

    protected function checkTopRated(JobWorker $worker): bool
    {
        return $worker->rating_count >= 10 && $worker->average_rating >= 4.8;
    }

    protected function checkQuickResponder(JobWorker $worker): bool
    {
        // Worker has applied to at least 10 jobs within 5 minutes of posting
        $quickApplications = $worker->applications()
            ->whereRaw('TIMESTAMPDIFF(MINUTE, job_posts.created_at, job_applications.created_at) <= 5')
            ->join('job_posts', 'job_applications.job_post_id', '=', 'job_posts.id')
            ->count();

        return $quickApplications >= 10;
    }

    protected function checkVeteran(JobWorker $worker): bool
    {
        $monthsActive = $worker->created_at->diffInMonths(now());
        return $monthsActive >= 6 && $worker->jobs_completed >= 50;
    }
}
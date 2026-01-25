<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FishAlert;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Command to display fish module statistics.
 *
 * Usage:
 * - php artisan fish:stats
 * - php artisan fish:stats --period=week
 * - php artisan fish:stats --json
 *
 * @srs-ref Pacha Meen Module - Analytics
 */
class FishStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fish:stats 
                            {--period=today : Period to report (today, week, month, all)}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Display fish module statistics and analytics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $period = $this->option('period');
        $asJson = $this->option('json');

        $dateFilter = $this->getDateFilter($period);

        $stats = $this->gatherStats($dateFilter);

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $this->displayStats($stats, $period);

        return Command::SUCCESS;
    }

    /**
     * Get date filter based on period.
     */
    protected function getDateFilter(string $period): ?\Carbon\Carbon
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'all' => null,
            default => now()->startOfDay(),
        };
    }

    /**
     * Gather all statistics.
     */
    protected function gatherStats(?\Carbon\Carbon $since): array
    {
        $catchQuery = FishCatch::query();
        $alertQuery = FishAlert::query();
        $subscriptionQuery = FishSubscription::query();
        $sellerQuery = FishSeller::query();

        if ($since) {
            $catchQuery->where('created_at', '>=', $since);
            $alertQuery->where('created_at', '>=', $since);
        }

        return [
            'sellers' => [
                'total' => FishSeller::count(),
                'active' => FishSeller::where('is_active', true)->count(),
                'verified' => FishSeller::whereNotNull('verified_at')->count(),
            ],
            'catches' => [
                'total' => $catchQuery->count(),
                'available' => FishCatch::where('status', 'available')->count(),
                'low_stock' => FishCatch::where('status', 'low_stock')->count(),
                'sold_out' => FishCatch::where('status', 'sold_out')->count(),
                'expired' => $since 
                    ? FishCatch::where('status', 'expired')->where('created_at', '>=', $since)->count()
                    : FishCatch::where('status', 'expired')->count(),
            ],
            'subscriptions' => [
                'total' => FishSubscription::count(),
                'active' => FishSubscription::where('is_active', true)->where('is_paused', false)->count(),
                'paused' => FishSubscription::where('is_paused', true)->count(),
                'by_frequency' => [
                    'instant' => FishSubscription::where('alert_frequency', 'instant')->count(),
                    'hourly' => FishSubscription::where('alert_frequency', 'hourly')->count(),
                    'daily' => FishSubscription::where('alert_frequency', 'daily')->count(),
                ],
            ],
            'alerts' => [
                'total' => $alertQuery->count(),
                'sent' => $alertQuery->clone()->whereNotNull('sent_at')->count(),
                'delivered' => $alertQuery->clone()->whereNotNull('delivered_at')->count(),
                'clicked' => $alertQuery->clone()->whereNotNull('clicked_at')->count(),
                'failed' => $alertQuery->clone()->whereNotNull('failed_at')->count(),
                'pending' => $alertQuery->clone()->whereNull('sent_at')->whereNull('failed_at')->count(),
            ],
            'engagement' => [
                'total_views' => FishCatch::sum('views'),
                'total_coming_responses' => DB::table('fish_catch_responses')
                    ->where('response_type', 'coming')
                    ->when($since, fn($q) => $q->where('created_at', '>=', $since))
                    ->count(),
                'avg_response_rate' => $this->calculateResponseRate($since),
            ],
            'top_fish_types' => $this->getTopFishTypes($since),
            'top_sellers' => $this->getTopSellers($since),
        ];
    }

    /**
     * Calculate alert click/response rate.
     */
    protected function calculateResponseRate(?\Carbon\Carbon $since): float
    {
        $query = FishAlert::whereNotNull('sent_at');
        
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $sent = $query->count();
        $clicked = $query->clone()->whereNotNull('clicked_at')->count();

        return $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0;
    }

    /**
     * Get top fish types by catch count.
     */
    protected function getTopFishTypes(?\Carbon\Carbon $since): array
    {
        $query = FishCatch::select('fish_type_id', DB::raw('count(*) as count'))
            ->groupBy('fish_type_id')
            ->orderByDesc('count')
            ->limit(5);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->with('fishType:id,name_en,name_ml')
            ->get()
            ->map(fn($item) => [
                'fish_type' => $item->fishType?->name_en ?? 'Unknown',
                'count' => $item->count,
            ])
            ->toArray();
    }

    /**
     * Get top sellers by catch count.
     */
    protected function getTopSellers(?\Carbon\Carbon $since): array
    {
        $query = FishCatch::select('fish_seller_id', DB::raw('count(*) as count'))
            ->groupBy('fish_seller_id')
            ->orderByDesc('count')
            ->limit(5);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->with('seller:id,business_name')
            ->get()
            ->map(fn($item) => [
                'seller' => $item->seller?->business_name ?? 'Unknown',
                'catches' => $item->count,
            ])
            ->toArray();
    }

    /**
     * Display stats in formatted output.
     */
    protected function displayStats(array $stats, string $period): void
    {
        $this->newLine();
        $this->info("🐟 Fish Module Statistics ({$period})");
        $this->line(str_repeat('=', 50));

        // Sellers
        $this->newLine();
        $this->comment('👥 Sellers');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sellers', $stats['sellers']['total']],
                ['Active', $stats['sellers']['active']],
                ['Verified', $stats['sellers']['verified']],
            ]
        );

        // Catches
        $this->comment('🎣 Catches');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['catches']['total']],
                ['Available', $stats['catches']['available']],
                ['Low Stock', $stats['catches']['low_stock']],
                ['Sold Out', $stats['catches']['sold_out']],
                ['Expired', $stats['catches']['expired']],
            ]
        );

        // Subscriptions
        $this->comment('🔔 Subscriptions');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total', $stats['subscriptions']['total']],
                ['Active', $stats['subscriptions']['active']],
                ['Paused', $stats['subscriptions']['paused']],
                ['Instant Alerts', $stats['subscriptions']['by_frequency']['instant']],
                ['Hourly Digest', $stats['subscriptions']['by_frequency']['hourly']],
                ['Daily Digest', $stats['subscriptions']['by_frequency']['daily']],
            ]
        );

        // Alerts
        $this->comment('📨 Alerts');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['alerts']['total']],
                ['Sent', $stats['alerts']['sent']],
                ['Delivered', $stats['alerts']['delivered']],
                ['Clicked', $stats['alerts']['clicked']],
                ['Failed', $stats['alerts']['failed']],
                ['Pending', $stats['alerts']['pending']],
            ]
        );

        // Engagement
        $this->comment('📊 Engagement');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Views', number_format($stats['engagement']['total_views'])],
                ['Coming Responses', $stats['engagement']['total_coming_responses']],
                ['Alert Response Rate', $stats['engagement']['avg_response_rate'] . '%'],
            ]
        );

        // Top Fish Types
        if (!empty($stats['top_fish_types'])) {
            $this->comment('🏆 Top Fish Types');
            $this->table(
                ['Fish Type', 'Catches'],
                array_map(fn($item) => [$item['fish_type'], $item['count']], $stats['top_fish_types'])
            );
        }

        // Top Sellers
        if (!empty($stats['top_sellers'])) {
            $this->comment('⭐ Top Sellers');
            $this->table(
                ['Seller', 'Catches'],
                array_map(fn($item) => [$item['seller'], $item['catches']], $stats['top_sellers'])
            );
        }
    }
}

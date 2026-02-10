<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FishAlert;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use App\Models\FishType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fish module statistics.
 *
 * Run daily at 8 AM for admin reporting.
 *
 * Usage:
 *   php artisan fish:stats                    # Today's stats
 *   php artisan fish:stats --period=week      # Last 7 days
 *   php artisan fish:stats --period=month     # Last 30 days
 *   php artisan fish:stats --json             # JSON output
 */
class FishStatsCommand extends Command
{
    protected $signature = 'fish:stats 
                            {--period=today : Period (today, week, month, all)}
                            {--json : Output as JSON}
                            {--brief : Brief summary only}';

    protected $description = 'Display fish module statistics (catches, alerts, sold out %, top fish)';

    public function handle(): int
    {
        $period = $this->option('period');
        $asJson = $this->option('json');
        $brief = $this->option('brief');

        $since = $this->getSinceDate($period);
        $stats = $this->gatherStats($since);

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($brief) {
            $this->displayBrief($stats, $period);
        } else {
            $this->displayFull($stats, $period);
        }

        return Command::SUCCESS;
    }

    protected function getSinceDate(string $period): ?\Carbon\Carbon
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'all' => null,
            default => now()->startOfDay(),
        };
    }

    protected function gatherStats(?\Carbon\Carbon $since): array
    {
        return [
            'period' => [
                'since' => $since?->toDateTimeString(),
                'until' => now()->toDateTimeString(),
            ],
            'sellers' => $this->getSellerStats(),
            'catches' => $this->getCatchStats($since),
            'subscriptions' => $this->getSubscriptionStats(),
            'alerts' => $this->getAlertStats($since),
            'top_fish' => $this->getTopFishTypes($since, 5),
            'top_sellers' => $this->getTopSellers($since, 5),
        ];
    }

    protected function getSellerStats(): array
    {
        return [
            'total' => FishSeller::count(),
            'active' => FishSeller::where('is_active', true)->count(),
            'verified' => FishSeller::whereNotNull('verified_at')->count(),
            'pending' => FishSeller::whereNull('verified_at')->where('is_active', true)->count(),
        ];
    }

    protected function getCatchStats(?\Carbon\Carbon $since): array
    {
        $baseQuery = FishCatch::query();
        if ($since) {
            $baseQuery->where('created_at', '>=', $since);
        }

        $posted = $baseQuery->count();
        
        // Current status counts (not filtered by time)
        $available = FishCatch::where('status', 'available')->count();
        $lowStock = FishCatch::where('status', 'low_stock')->count();
        $soldOut = FishCatch::where('status', 'sold_out')
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->count();
        $expired = FishCatch::where('status', 'expired')
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->count();

        // Sold out % = catches that sold out / total posted
        $soldOutRate = $posted > 0 ? round(($soldOut / $posted) * 100, 1) : 0;

        return [
            'posted' => $posted,
            'available_now' => $available,
            'low_stock' => $lowStock,
            'sold_out' => $soldOut,
            'expired' => $expired,
            'sold_out_rate' => $soldOutRate,
        ];
    }

    protected function getSubscriptionStats(): array
    {
        return [
            'total' => FishSubscription::count(),
            'active' => FishSubscription::where('is_active', true)->where('is_paused', false)->count(),
            'paused' => FishSubscription::where('is_paused', true)->count(),
            'by_frequency' => [
                'anytime' => FishSubscription::where('alert_frequency', 'anytime')->count(),
                'early_morning' => FishSubscription::where('alert_frequency', 'early_morning')->count(),
                'morning' => FishSubscription::where('alert_frequency', 'morning')->count(),
                'twice_daily' => FishSubscription::where('alert_frequency', 'twice_daily')->count(),
            ],
        ];
    }

    protected function getAlertStats(?\Carbon\Carbon $since): array
    {
        $query = FishAlert::query();
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $total = $query->count();
        $sent = $query->clone()->where('status', 'sent')->count();
        $clicked = $query->clone()->whereNotNull('clicked_at')->count();
        $failed = $query->clone()->where('status', 'failed')->count();
        $pending = $query->clone()->where('status', 'pending')->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'clicked' => $clicked,
            'failed' => $failed,
            'pending' => $pending,
            'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 1) : 0,
            'delivery_rate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
        ];
    }

    protected function getTopFishTypes(?\Carbon\Carbon $since, int $limit): array
    {
        $query = FishCatch::select('fish_type_id', DB::raw('COUNT(*) as count'))
            ->groupBy('fish_type_id')
            ->orderByDesc('count')
            ->limit($limit);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get()->map(function ($item) {
            $fish = FishType::find($item->fish_type_id);
            return [
                'fish' => $fish?->display_name ?? '🐟 Unknown',
                'catches' => $item->count,
            ];
        })->toArray();
    }

    protected function getTopSellers(?\Carbon\Carbon $since, int $limit): array
    {
        $query = FishCatch::select('fish_seller_id', DB::raw('COUNT(*) as count'))
            ->groupBy('fish_seller_id')
            ->orderByDesc('count')
            ->limit($limit);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get()->map(function ($item) {
            $seller = FishSeller::find($item->fish_seller_id);
            return [
                'seller' => $seller?->business_name ?? 'Unknown',
                'catches' => $item->count,
            ];
        })->toArray();
    }

    protected function displayBrief(array $stats, string $period): void
    {
        $c = $stats['catches'];
        $a = $stats['alerts'];
        $s = $stats['subscriptions'];

        $this->newLine();
        $this->info("🐟 Fish Stats ({$period})");
        $this->line("Catches: {$c['posted']} posted | {$c['sold_out_rate']}% sold out | {$c['expired']} expired");
        $this->line("Alerts: {$a['sent']} sent | {$a['click_rate']}% clicked | {$a['pending']} pending");
        $this->line("Subscribers: {$s['active']} active | {$s['paused']} paused");
    }

    protected function displayFull(array $stats, string $period): void
    {
        $this->newLine();
        $this->info("🐟 Fish Module Stats ({$period})");
        $this->line(str_repeat('═', 50));

        // Sellers
        $this->newLine();
        $this->comment('👥 Sellers');
        $this->table(['Metric', 'Count'], [
            ['Total', $stats['sellers']['total']],
            ['Active', $stats['sellers']['active']],
            ['Verified', $stats['sellers']['verified']],
            ['Pending Verification', $stats['sellers']['pending']],
        ]);

        // Catches
        $this->comment('🎣 Catches');
        $this->table(['Status', 'Count'], [
            ['Posted (period)', $stats['catches']['posted']],
            ['Available Now', $stats['catches']['available_now']],
            ['Low Stock', $stats['catches']['low_stock']],
            ['Sold Out', $stats['catches']['sold_out']],
            ['Expired', $stats['catches']['expired']],
            ['*Sold Out Rate*', $stats['catches']['sold_out_rate'] . '%'],
        ]);

        // Subscriptions (PM-014 frequencies)
        $this->comment('🔔 Subscriptions');
        $this->table(['Metric', 'Count'], [
            ['Total', $stats['subscriptions']['total']],
            ['Active', $stats['subscriptions']['active']],
            ['Paused', $stats['subscriptions']['paused']],
            ['🔔 Anytime (instant)', $stats['subscriptions']['by_frequency']['anytime']],
            ['🌅 Early Morning (5-7)', $stats['subscriptions']['by_frequency']['early_morning']],
            ['☀️ Morning (7-9)', $stats['subscriptions']['by_frequency']['morning']],
            ['📅 Twice Daily', $stats['subscriptions']['by_frequency']['twice_daily']],
        ]);

        // Alerts
        $this->comment('📨 Alerts');
        $this->table(['Metric', 'Value'], [
            ['Total', $stats['alerts']['total']],
            ['Sent', $stats['alerts']['sent']],
            ['Clicked', $stats['alerts']['clicked']],
            ['Failed', $stats['alerts']['failed']],
            ['Pending', $stats['alerts']['pending']],
            ['*Click Rate*', $stats['alerts']['click_rate'] . '%'],
            ['*Delivery Rate*', $stats['alerts']['delivery_rate'] . '%'],
        ]);

        // Top Fish
        if (!empty($stats['top_fish'])) {
            $this->comment('🏆 Top Fish Types');
            $this->table(
                ['Fish', 'Catches'],
                array_map(fn($f) => [$f['fish'], $f['catches']], $stats['top_fish'])
            );
        }

        // Top Sellers
        if (!empty($stats['top_sellers'])) {
            $this->comment('⭐ Top Sellers');
            $this->table(
                ['Seller', 'Catches'],
                array_map(fn($s) => [$s['seller'], $s['catches']], $stats['top_sellers'])
            );
        }
    }
}
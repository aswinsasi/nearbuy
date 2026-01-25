<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FishAlert;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use App\Models\FishType;
use Illuminate\View\View;

/**
 * Admin dashboard controller for fish module.
 */
class FishDashboardController extends Controller
{
    /**
     * Display fish module dashboard.
     */
    public function index(): View
    {
        $stats = [
            'sellers' => [
                'total' => FishSeller::count(),
                'active' => FishSeller::where('is_active', true)->count(),
                'verified' => FishSeller::whereNotNull('verified_at')->count(),
            ],
            'catches' => [
                'today' => FishCatch::whereDate('created_at', today())->count(),
                'available' => FishCatch::where('status', 'available')->count(),
                'low_stock' => FishCatch::where('status', 'low_stock')->count(),
                'sold_out' => FishCatch::where('status', 'sold_out')->count(),
                'expired' => FishCatch::where('status', 'expired')->count(),
            ],
            'subscriptions' => [
                'total' => FishSubscription::count(),
                'active' => FishSubscription::where('is_active', true)->where('is_paused', false)->count(),
                'paused' => FishSubscription::where('is_paused', true)->count(),
                'by_frequency' => [
                    FishSubscription::where('alert_frequency', 'immediate')->count(),
                    FishSubscription::where('alert_frequency', 'morning_only')->count(),
                    FishSubscription::where('alert_frequency', 'twice_daily')->count(),
                    FishSubscription::where('alert_frequency', 'weekly_digest')->count(),
                ],
            ],
            'alerts' => [
                'sent_today' => FishAlert::whereDate('sent_at', today())->count(),
                'pending' => FishAlert::whereNull('sent_at')->whereNull('failed_at')->count(),
                'failed_today' => FishAlert::whereDate('failed_at', today())->count(),
            ],
        ];

        $recentCatches = FishCatch::with(['fishType', 'seller'])
            ->latest()
            ->limit(5)
            ->get();

        $recentSellers = FishSeller::with('user')
            ->latest()
            ->limit(5)
            ->get();

        $topFishTypes = FishType::withCount('catches')
            ->orderByDesc('catches_count')
            ->limit(5)
            ->get();

        return view('admin.fish.dashboard', compact(
            'stats',
            'recentCatches',
            'recentSellers',
            'topFishTypes'
        ));
    }
}

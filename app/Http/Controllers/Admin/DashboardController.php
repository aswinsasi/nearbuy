<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Offer;
use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard controller.
 */
class DashboardController extends Controller
{
    /**
     * Show dashboard.
     */
    public function index()
    {
        $stats = $this->getStats();
        $charts = $this->getChartData();
        $recentActivity = $this->getRecentActivity();

        return view('admin.dashboard', compact('stats', 'charts', 'recentActivity'));
    }

    /**
     * Get dashboard statistics.
     */
    protected function getStats(): array
    {
        return [
            'total_users' => User::count(),
            'customers' => User::whereDoesntHave('shop')->count(),
            'shop_owners' => User::whereHas('shop')->count(),
            'total_shops' => Shop::count(),
            'verified_shops' => Shop::where('verified', true)->count(),
            'active_offers' => Offer::where('is_active', true)
                ->where('valid_until', '>', now())
                ->count(),
            'requests_today' => ProductRequest::whereDate('created_at', today())->count(),
            'requests_this_week' => ProductRequest::whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ])->count(),
            'responses_today' => ProductResponse::whereDate('created_at', today())->count(),
            'agreements_this_month' => Agreement::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'pending_agreements' => Agreement::where('status', 'pending')->count(),
            'total_agreements_value' => Agreement::where('status', 'confirmed')->sum('amount'),
        ];
    }

    /**
     * Get chart data.
     */
    protected function getChartData(): array
    {
        // Users registered per day (last 30 days)
        $usersByDay = User::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Requests per day (last 14 days)
        $requestsByDay = ProductRequest::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Shops by category
        $shopsByCategory = Shop::select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        // Agreements by status
        $agreementsByStatus = Agreement::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Fill missing dates for line charts
        $last30Days = collect();
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last30Days[$date] = $usersByDay[$date] ?? 0;
        }

        $last14Days = collect();
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last14Days[$date] = $requestsByDay[$date] ?? 0;
        }

        return [
            'users' => [
                'labels' => $last30Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last30Days->values()->toArray(),
            ],
            'requests' => [
                'labels' => $last14Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last14Days->values()->toArray(),
            ],
            'categories' => [
                'labels' => array_keys($shopsByCategory),
                'data' => array_values($shopsByCategory),
            ],
            'agreements' => [
                'labels' => array_keys($agreementsByStatus),
                'data' => array_values($agreementsByStatus),
            ],
        ];
    }

    /**
     * Get recent activity.
     */
    protected function getRecentActivity(): array
    {
        return [
            'recent_users' => User::with('shop')
                ->latest()
                ->take(5)
                ->get(),
            'recent_requests' => ProductRequest::with('user')
                ->latest()
                ->take(5)
                ->get(),
            'recent_agreements' => Agreement::with('creator')
                ->latest()
                ->take(5)
                ->get(),
        ];
    }
}
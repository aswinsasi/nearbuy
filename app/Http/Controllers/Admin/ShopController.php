<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductResponse;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Admin shop management controller.
 */
class ShopController extends Controller
{
    /**
     * List all shops.
     */
    public function index(Request $request)
    {
        $query = Shop::with('owner');

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by verification status
        if ($request->filled('verified')) {
            $query->where('verified', $request->verified === 'yes');
        }

        // Filter by active status
        if ($request->filled('active')) {
            $query->where('is_active', $request->active === 'yes');
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('shop_name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $shops = $query->withCount(['offers', 'responses'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $categories = Shop::distinct()->pluck('category')->filter()->sort();

        return view('admin.shops.index', compact('shops', 'categories'));
    }

    /**
     * Show shop details.
     */
    public function show(Shop $shop)
    {
        $shop->load(['owner', 'offers' => function ($q) {
            $q->latest()->take(10);
        }]);

        $responses = ProductResponse::where('shop_id', $shop->id)
            ->with('request')
            ->latest()
            ->take(20)
            ->get();

        $stats = [
            'total_offers' => $shop->offers()->count(),
            'active_offers' => $shop->offers()->where('is_active', true)->count(),
            'total_responses' => $shop->responses()->count(),
            'available_responses' => $shop->responses()->where('is_available', true)->count(),
            'avg_response_time' => $this->calculateAvgResponseTime($shop),
        ];

        return view('admin.shops.show', compact('shop', 'responses', 'stats'));
    }

    /**
     * Toggle shop verification status.
     */
    public function toggleVerification(Shop $shop)
    {
        $shop->update(['verified' => !$shop->verified]);

        $status = $shop->verified ? 'verified' : 'unverified';

        return back()->with('success', "Shop {$status} successfully.");
    }

    /**
     * Toggle shop active status.
     */
    public function toggleActive(Shop $shop)
    {
        $shop->update(['is_active' => !$shop->is_active]);

        $status = $shop->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Shop {$status} successfully.");
    }

    /**
     * Calculate average response time for shop.
     */
    protected function calculateAvgResponseTime(Shop $shop): ?string
    {
        $responses = ProductResponse::where('shop_id', $shop->id)
            ->whereNotNull('created_at')
            ->with('request')
            ->get();

        if ($responses->isEmpty()) {
            return null;
        }

        $totalMinutes = 0;
        $count = 0;

        foreach ($responses as $response) {
            if ($response->request) {
                $minutes = $response->request->created_at->diffInMinutes($response->created_at);
                $totalMinutes += $minutes;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        $avgMinutes = round($totalMinutes / $count);

        if ($avgMinutes < 60) {
            return "{$avgMinutes} min";
        }

        $hours = round($avgMinutes / 60, 1);
        return "{$hours} hrs";
    }
}
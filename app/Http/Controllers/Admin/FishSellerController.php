<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\FishSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin controller for managing fish sellers.
 */
class FishSellerController extends Controller
{
    /**
     * List all fish sellers.
     */
    public function index(Request $request): View
    {
        $query = FishSeller::with(['user:id,name,phone', 'catches' => function ($q) {
            $q->where('status', 'available');
        }]);

        if ($request->has('status') && $request->status !== '') {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->has('verified') && $request->verified !== '') {
            if ($request->verified === 'yes') {
                $query->whereNotNull('verified_at');
            } else {
                $query->whereNull('verified_at');
            }
        }

        if ($request->filled('seller_type')) {
            $query->where('seller_type', $request->seller_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('phone', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $sellers = $query->latest()->paginate(20)->withQueryString();

        return view('admin.fish.sellers.index', compact('sellers'));
    }

    /**
     * Show seller details.
     */
    public function show(FishSeller $seller): View
    {
        $seller->load([
            'user:id,name,phone',
            'catches' => function ($q) {
                $q->whereIn('status', ['available', 'low_stock'])
                    ->with('fishType')
                    ->latest();
            },
        ]);

        $stats = [
            'today_catches' => FishCatch::where('fish_seller_id', $seller->id)
                ->whereDate('created_at', today())
                ->count(),
            'today_views' => FishCatch::where('fish_seller_id', $seller->id)
                ->whereDate('created_at', today())
                ->sum('view_count'),
            'week_catches' => FishCatch::where('fish_seller_id', $seller->id)
                ->where('created_at', '>=', now()->subWeek())
                ->count(),
            'total_catches' => FishCatch::where('fish_seller_id', $seller->id)->count(),
            'total_views' => FishCatch::where('fish_seller_id', $seller->id)->sum('view_count'),
            'week_coming' => DB::table('fish_catch_responses')
                ->whereIn('fish_catch_id', function ($q) use ($seller) {
                    $q->select('id')->from('fish_catches')->where('fish_seller_id', $seller->id);
                })
                ->where('response_type', 'coming')
                ->where('created_at', '>=', now()->subWeek())
                ->count(),
        ];

        return view('admin.fish.sellers.show', compact('seller', 'stats'));
    }

    /**
     * Verify a seller.
     */
    public function verify(FishSeller $seller): RedirectResponse
    {
        $seller->update(['verified_at' => now()]);

        return back()->with('success', 'Seller verified successfully');
    }

    /**
     * Deactivate a seller.
     */
    public function deactivate(Request $request, FishSeller $seller): RedirectResponse
    {
        $seller->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $request->input('reason'),
        ]);

        return back()->with('success', 'Seller deactivated');
    }

    /**
     * Reactivate a seller.
     */
    public function reactivate(FishSeller $seller): RedirectResponse
    {
        $seller->update([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        return back()->with('success', 'Seller reactivated');
    }

    /**
     * Update seller details.
     */
    public function update(Request $request, FishSeller $seller): RedirectResponse
    {
        $validated = $request->validate([
            'business_name' => 'required|string|min:2|max:100',
            'seller_type' => 'required|in:fisherman,harbour_vendor,fish_shop,wholesaler',
            'market_name' => 'nullable|string|max:100',
        ]);

        $seller->update($validated);

        return back()->with('success', 'Seller updated successfully');
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\FishCatchStatus;
use App\Models\FishCatch;
use App\Models\FishType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin controller for managing fish catches.
 */
class FishCatchController extends Controller
{
    /**
     * List all catches.
     */
    public function index(Request $request): View
    {
        $query = FishCatch::with([
            'fishType:id,name_en,name_ml',
            'seller:id,business_name,user_id',
            'seller.user:id,phone',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('fish_type_id')) {
            $query->where('fish_type_id', $request->fish_type_id);
        }

        if ($request->filled('seller_id')) {
            $query->where('fish_seller_id', $request->seller_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('catch_number', 'like', "%{$search}%")
                    ->orWhereHas('fishType', function ($fq) use ($search) {
                        $fq->where('name_en', 'like', "%{$search}%")
                            ->orWhere('name_ml', 'like', "%{$search}%");
                    });
            });
        }

        $catches = $query->latest()->paginate(20)->withQueryString();
        $fishTypes = FishType::where('is_active', true)->orderBy('name_en')->get();

        return view('admin.fish.catches.index', compact('catches', 'fishTypes'));
    }

    /**
     * Show catch details.
     */
    public function show(FishCatch $catch): View
    {
        $catch->load([
            'fishType',
            'seller.user:id,phone,name',
            'alerts' => function ($q) {
                $q->with('subscription.user:id,phone,name')->latest()->limit(20);
            },
        ]);

        $stats = [
            'views' => $catch->views ?? 0,
            'coming_count' => DB::table('fish_catch_responses')
                ->where('fish_catch_id', $catch->id)
                ->where('response_type', 'coming')
                ->count(),
            'message_count' => DB::table('fish_catch_responses')
                ->where('fish_catch_id', $catch->id)
                ->where('response_type', 'message')
                ->count(),
            'alerts_sent' => $catch->alerts()->whereNotNull('sent_at')->count(),
        ];

        return view('admin.fish.catches.show', compact('catch', 'stats'));
    }

    /**
     * Update catch status.
     */
    public function updateStatus(Request $request, FishCatch $catch): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:available,low_stock,sold_out,expired',
        ]);

        $catch->update([
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Catch status updated');
    }

    /**
     * Extend catch expiry time.
     */
    public function extendExpiry(Request $request, FishCatch $catch): RedirectResponse
    {
        $validated = $request->validate([
            'hours' => 'required|integer|min:1|max:24',
        ]);

        $hours = (int) $validated['hours'];

        $catch->update([
            'expires_at' => ($catch->expires_at ?? now())->addHours($hours),
        ]);

        return back()->with('success', "Catch expiry extended by {$hours} hours");
    }

    /**
     * Delete a catch.
     */
    public function destroy(FishCatch $catch): RedirectResponse
    {
        $catch->delete();

        return redirect()->route('admin.fish.catches.index')
            ->with('success', 'Catch deleted');
    }

    /**
     * Expire stale catches.
     */
    public function expireStale(): RedirectResponse
    {
        $count = FishCatch::where('status', 'available')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
            ]);

        return back()->with('success', "{$count} stale catches expired");
    }
}
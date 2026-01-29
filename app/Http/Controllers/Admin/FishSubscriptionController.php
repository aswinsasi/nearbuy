<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FishSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin controller for managing fish subscriptions.
 */
class FishSubscriptionController extends Controller
{
    /**
     * List all subscriptions.
     */
    public function index(Request $request): View
    {
        $query = FishSubscription::with([
            'user:id,name,phone',
        ]);

        if ($request->has('active') && $request->active !== '') {
            $query->where('is_active', $request->active === 'true');
        }

        if ($request->has('paused') && $request->paused !== '') {
            $query->where('is_paused', $request->paused === 'true');
        }

        if ($request->filled('frequency')) {
            $query->where('alert_frequency', $request->frequency);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($uq) use ($search) {
                $uq->where('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $subscriptions = $query->latest()->paginate(20)->withQueryString();

        return view('admin.fish.subscriptions.index', compact('subscriptions'));
    }

    /**
     * Show subscription details.
     */
    public function show(FishSubscription $subscription): View
    {
        $subscription->load([
            'user:id,name,phone',
            'alerts' => function ($q) {
                $q->with(['catch.fishType', 'catch.seller'])->latest()->limit(10);
            },
        ]);

        $alertsReceived = $subscription->alerts()->count();
        $alertsDelivered = $subscription->alerts()->whereNotNull('delivered_at')->count();
        $alertsClicked = $subscription->alerts()->whereNotNull('clicked_at')->count();

        $stats = [
            'alerts_received' => $alertsReceived,
            'alerts_delivered' => $alertsDelivered,
            'alerts_clicked' => $alertsClicked,
            'click_rate' => $alertsDelivered > 0 ? round(($alertsClicked / $alertsDelivered) * 100, 1) : 0,
        ];

        return view('admin.fish.subscriptions.show', compact('subscription', 'stats'));
    }

    /**
     * Deactivate subscription.
     */
    public function deactivate(FishSubscription $subscription): RedirectResponse
    {
        $subscription->update(['is_active' => false]);

        return back()->with('success', 'Subscription deactivated');
    }

    /**
     * Activate subscription.
     */
    public function activate(FishSubscription $subscription): RedirectResponse
    {
        $subscription->update([
            'is_active' => true,
            'is_paused' => false,
        ]);

        return back()->with('success', 'Subscription activated');
    }

    /**
     * Delete subscription.
     */
    public function destroy(FishSubscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('admin.fish.subscriptions.index')
            ->with('success', 'Subscription deleted');
    }
}
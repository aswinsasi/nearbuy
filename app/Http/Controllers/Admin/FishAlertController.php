<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Fish\SendFishAlertJob;
use App\Models\FishAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin controller for managing fish alerts.
 */
class FishAlertController extends Controller
{
    /**
     * List all alerts.
     */
    public function index(Request $request): View
    {
        $query = FishAlert::with([
            'subscription.user:id,phone,name',
            'catch.fishType:id,name_en',
            'catch.seller:id,business_name',
        ]);

        if ($request->filled('status')) {
            switch ($request->status) {
                case 'pending':
                    $query->whereNull('sent_at')->whereNull('failed_at');
                    break;
                case 'sent':
                    $query->whereNotNull('sent_at')->whereNull('delivered_at');
                    break;
                case 'delivered':
                    $query->whereNotNull('delivered_at')->whereNull('clicked_at');
                    break;
                case 'clicked':
                    $query->whereNotNull('clicked_at');
                    break;
                case 'failed':
                    $query->whereNotNull('failed_at');
                    break;
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $alerts = $query->latest()->paginate(50)->withQueryString();

        $alertStats = [
            'total' => FishAlert::count(),
            'sent' => FishAlert::whereNotNull('sent_at')->count(),
            'delivered' => FishAlert::whereNotNull('delivered_at')->count(),
            'clicked' => FishAlert::whereNotNull('clicked_at')->count(),
            'failed' => FishAlert::whereNotNull('failed_at')->count(),
            'pending' => FishAlert::whereNull('sent_at')->whereNull('failed_at')->count(),
        ];

        return view('admin.fish.alerts.index', compact('alerts', 'alertStats'));
    }

    /**
     * Show alert details.
     */
    public function show(FishAlert $alert): View
    {
        $alert->load([
            'subscription.user',
            'subscription.fishTypes',
            'catch.fishType',
            'catch.seller.user',
        ]);

        return view('admin.fish.alerts.show', compact('alert'));
    }

    /**
     * Retry a failed alert.
     */
    public function retry(FishAlert $alert): RedirectResponse
    {
        if (!$alert->failed_at) {
            return back()->with('error', 'Alert has not failed');
        }

        $alert->update([
            'failed_at' => null,
            'failure_reason' => null,
            'sent_at' => null,
        ]);

        SendFishAlertJob::dispatch($alert);

        return back()->with('success', 'Alert retry queued');
    }

    /**
     * Process pending alerts.
     */
    public function processPending(): RedirectResponse
    {
        $alerts = FishAlert::whereNull('sent_at')
            ->whereNull('failed_at')
            ->limit(100)
            ->get();

        $count = 0;
        foreach ($alerts as $alert) {
            SendFishAlertJob::dispatch($alert);
            $count++;
        }

        return back()->with('success', "{$count} alerts queued for processing");
    }

    /**
     * Cleanup old alerts.
     */
    public function cleanup(Request $request): RedirectResponse
    {
        $days = (int) $request->get('days', 30);

        $deleted = FishAlert::where('created_at', '<', now()->subDays($days))->delete();

        return back()->with('success', "{$deleted} old alerts cleaned up");
    }
}

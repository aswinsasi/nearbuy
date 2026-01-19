<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\Request;

/**
 * Admin offer management controller.
 */
class OfferController extends Controller
{
    /**
     * List all offers.
     */
    public function index(Request $request)
    {
        $query = Offer::with('shop.owner');

        // Filter by status (using model scopes)
        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->active(),
                'expired' => $query->expired(),
                'inactive' => $query->where('is_active', false),
                default => null,
            };
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->whereHas('shop', function ($q) use ($request) {
                $q->where('category', $request->category);
            });
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('caption', 'like', "%{$search}%")
                    ->orWhereHas('shop', function ($q) use ($search) {
                        $q->where('shop_name', 'like', "%{$search}%");
                    });
            });
        }

        $offers = $query->latest()->paginate(20)->withQueryString();

        return view('admin.offers.index', compact('offers'));
    }

    /**
     * Show offer details.
     */
    public function show(Offer $offer)
    {
        $offer->load('shop.owner');

        return view('admin.offers.show', compact('offer'));
    }

    /**
     * Toggle offer active status.
     */
    public function toggleActive(Offer $offer)
    {
        $offer->update(['is_active' => !$offer->is_active]);

        $status = $offer->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Offer {$status} successfully.");
    }

    /**
     * Delete offer.
     */
    public function destroy(Offer $offer)
    {
        $offer->delete();

        return redirect()->route('admin.offers.index')
            ->with('success', 'Offer deleted successfully.');
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\FishType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin controller for managing fish types.
 */
class FishTypeController extends Controller
{
    /**
     * List all fish types.
     */
    public function index(Request $request): View
    {
        $query = FishType::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('active') && $request->active !== '') {
            $query->where('is_active', $request->active === 'true');
        }

        if ($request->has('popular') && $request->popular === 'true') {
            $query->where('is_popular', true);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'like', "%{$search}%")
                    ->orWhere('name_ml', 'like', "%{$search}%")
                    ->orWhere('local_names', 'like', "%{$search}%");
            });
        }

        $query->withCount(['catches', 'catches as active_catches_count' => function ($q) {
            $q->where('status', 'available');
        }]);

        $fishTypes = $query->orderBy('name_en')->paginate(20)->withQueryString();

        return view('admin.fish.types.index', compact('fishTypes'));
    }

    /**
     * Show fish type details.
     */
    public function show(FishType $fishType): View
    {
        $fishType->loadCount([
            'catches',
            'catches as active_catches_count' => fn($q) => $q->where('status', 'available'),
        ]);

        $priceStats = FishCatch::where('fish_type_id', $fishType->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('MIN(price_per_kg) as min, MAX(price_per_kg) as max, AVG(price_per_kg) as avg')
            ->first();

        $recentCatches = FishCatch::where('fish_type_id', $fishType->id)
            ->with('seller')
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.fish.types.show', compact('fishType', 'priceStats', 'recentCatches'));
    }

    /**
     * Store a new fish type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name_en' => 'required|string|min:2|max:100|unique:fish_types,name_en',
            'name_ml' => 'required|string|min:2|max:100',
            'category' => 'required|in:marine,freshwater,brackish,shellfish',
            'local_names' => 'nullable|string|max:255',
            'is_popular' => 'nullable',
        ]);

        $validated['slug'] = Str::slug($validated['name_en']);
        $validated['is_popular'] = $request->has('is_popular');
        $validated['is_active'] = true;

        FishType::create($validated);

        return redirect()->route('admin.fish.types.index')
            ->with('success', 'Fish type created successfully');
    }

    /**
     * Update a fish type.
     */
    public function update(Request $request, FishType $fishType): RedirectResponse
    {
        $validated = $request->validate([
            'name_en' => "required|string|min:2|max:100|unique:fish_types,name_en,{$fishType->id}",
            'name_ml' => 'required|string|min:2|max:100',
            'scientific_name' => 'nullable|string|max:100',
            'category' => 'required|in:marine,freshwater,brackish,shellfish',
            'local_names' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $validated['slug'] = Str::slug($validated['name_en']);

        $fishType->update($validated);

        return redirect()->route('admin.fish.types.show', $fishType)
            ->with('success', 'Fish type updated successfully');
    }

    /**
     * Delete a fish type.
     */
    public function destroy(FishType $fishType): RedirectResponse
    {
        if ($fishType->catches()->where('status', 'available')->exists()) {
            return back()->with('error', 'Cannot delete fish type with active catches');
        }

        $fishType->delete();

        return redirect()->route('admin.fish.types.index')
            ->with('success', 'Fish type deleted successfully');
    }

    /**
     * Toggle popular status.
     */
    public function togglePopular(FishType $fishType): RedirectResponse
    {
        $fishType->update(['is_popular' => !$fishType->is_popular]);

        return back()->with('success', $fishType->is_popular ? 'Marked as popular' : 'Removed from popular');
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(FishType $fishType): RedirectResponse
    {
        $fishType->update(['is_active' => !$fishType->is_active]);

        return back()->with('success', $fishType->is_active ? 'Fish type activated' : 'Fish type deactivated');
    }
}

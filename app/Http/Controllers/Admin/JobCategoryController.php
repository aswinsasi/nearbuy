<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\JobStatus;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin controller for managing job categories.
 */
class JobCategoryController extends Controller
{
    /**
     * List all categories.
     */
    public function index(Request $request): View
    {
        $query = JobCategory::query();

        if ($request->has('active') && $request->active !== '') {
            $query->where('is_active', $request->active === 'true');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'like', "%{$search}%")
                    ->orWhere('name_ml', 'like', "%{$search}%");
            });
        }

        $query->withCount([
            'jobPosts',
            'jobPosts as open_jobs_count' => fn($q) => $q->where('status', JobStatus::OPEN),
        ]);

        $categories = $query->orderBy('sort_order')->orderBy('name_en')->paginate(20)->withQueryString();

        return view('admin.jobs.categories.index', compact('categories'));
    }

    /**
     * Show category details.
     */
    public function show(JobCategory $category): View
    {
        $category->loadCount([
            'jobPosts',
            'jobPosts as open_jobs_count' => fn($q) => $q->where('status', JobStatus::OPEN),
            'jobPosts as completed_jobs_count' => fn($q) => $q->where('status', JobStatus::COMPLETED),
        ]);

        // Price stats
        $priceStats = JobPost::where('job_category_id', $category->id)
            ->where('status', JobStatus::COMPLETED)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('MIN(pay_amount) as min, MAX(pay_amount) as max, AVG(pay_amount) as avg')
            ->first();

        // Recent jobs in this category
        $recentJobs = JobPost::where('job_category_id', $category->id)
            ->with(['poster:id,name', 'assignedWorker:id,name'])
            ->latest()
            ->limit(10)
            ->get();

        // Workers who do this category
        $workers = JobWorker::whereJsonContains('job_types', $category->id)
            ->orderByDesc('rating')
            ->limit(20)
            ->get(['id', 'name', 'rating', 'jobs_completed']);

        return view('admin.jobs.categories.show', compact('category', 'priceStats', 'recentJobs', 'workers'));
    }

    /**
     * Store a new category.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name_en' => 'required|string|min:2|max:100|unique:job_categories,name_en',
            'name_ml' => 'required|string|min:2|max:100',
            'icon' => 'nullable|string|max:10',
            'tier' => 'nullable|integer|in:1,2',
            'description' => 'nullable|string|max:500',
            'typical_pay_min' => 'nullable|numeric|min:0',
            'typical_pay_max' => 'nullable|numeric|min:0',
            'typical_duration_hours' => 'nullable|numeric|min:0',
            'requires_vehicle' => 'nullable',
            'is_popular' => 'nullable',
        ]);

        $validated['slug'] = Str::slug($validated['name_en']);
        $validated['requires_vehicle'] = $request->has('requires_vehicle');
        $validated['is_popular'] = $request->has('is_popular');
        $validated['is_active'] = true;
        $validated['tier'] = $validated['tier'] ?? 1;
        $validated['sort_order'] = JobCategory::max('sort_order') + 1;

        JobCategory::create($validated);

        return redirect()->route('admin.jobs.categories.index')
            ->with('success', 'Category created successfully');
    }

    /**
     * Update a category.
     */
    public function update(Request $request, JobCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name_en' => "required|string|min:2|max:100|unique:job_categories,name_en,{$category->id}",
            'name_ml' => 'required|string|min:2|max:100',
            'icon' => 'nullable|string|max:10',
            'tier' => 'nullable|integer|in:1,2',
            'description' => 'nullable|string|max:500',
            'typical_pay_min' => 'nullable|numeric|min:0',
            'typical_pay_max' => 'nullable|numeric|min:0',
            'typical_duration_hours' => 'nullable|numeric|min:0',
            'requires_vehicle' => 'nullable',
            'is_popular' => 'nullable',
        ]);

        $validated['slug'] = Str::slug($validated['name_en']);
        $validated['requires_vehicle'] = $request->has('requires_vehicle');
        $validated['is_popular'] = $request->has('is_popular');

        $category->update($validated);

        return back()->with('success', 'Category updated successfully');
    }

    /**
     * Delete a category.
     */
    public function destroy(JobCategory $category): RedirectResponse
    {
        if ($category->jobPosts()->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])->exists()) {
            return back()->with('error', 'Cannot delete category with active jobs');
        }

        $category->delete();

        return redirect()->route('admin.jobs.categories.index')
            ->with('success', 'Category deleted');
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(JobCategory $category): RedirectResponse
    {
        if ($category->is_active) {
            $activeJobs = $category->jobPosts()
                ->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
                ->count();

            if ($activeJobs > 0) {
                return back()->with('error', "Cannot deactivate: {$activeJobs} active jobs in this category");
            }
        }

        $category->update(['is_active' => !$category->is_active]);

        return back()->with('success', $category->is_active ? 'Category activated' : 'Category deactivated');
    }
}
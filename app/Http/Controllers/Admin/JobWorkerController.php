<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\JobStatus;
use App\Enums\VehicleType;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin controller for managing job workers.
 */
class JobWorkerController extends Controller
{
    /**
     * List all workers.
     */
    public function index(Request $request): View
    {
        $query = JobWorker::with(['user:id,name,phone']);

        // Status filter
        if ($request->has('status') && $request->status !== '') {
            match ($request->status) {
                'active' => $query->where('is_available', true),
                'inactive' => $query->where('is_available', false),
                'verified' => $query->where('is_verified', true),
                'pending' => $query->where('is_verified', false),
                default => null,
            };
        }

        // Vehicle filter
        if ($request->has('has_vehicle') && $request->has_vehicle !== '') {
            $request->has_vehicle === 'yes'
                ? $query->where('vehicle_type', '!=', 'none')
                : $query->where('vehicle_type', 'none');
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('phone', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $workers = $query->latest()->paginate(20)->withQueryString();

        return view('admin.jobs.workers.index', compact('workers'));
    }

    /**
     * Show worker details.
     */
    public function show(JobWorker $worker): View
    {
        $worker->load(['user:id,name,phone,email', 'badges']);

        // Get worker's category names
        $categoryIds = $worker->job_types ?? [];
        $categories = JobCategory::whereIn('id', $categoryIds)->get();

        $stats = [
            'total_jobs' => JobPost::where('assigned_worker_id', $worker->id)->count(),
            'completed_jobs' => JobPost::where('assigned_worker_id', $worker->id)
                ->where('status', JobStatus::COMPLETED)->count(),
            'active_jobs' => JobPost::where('assigned_worker_id', $worker->id)
                ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])->count(),
            'this_week_earnings' => JobPost::where('assigned_worker_id', $worker->id)
                ->where('status', JobStatus::COMPLETED)
                ->where('completed_at', '>=', now()->startOfWeek())
                ->sum('pay_amount'),
            'total_earnings' => JobPost::where('assigned_worker_id', $worker->id)
                ->where('status', JobStatus::COMPLETED)
                ->sum('pay_amount'),
        ];

        $jobHistory = JobPost::where('assigned_worker_id', $worker->id)
            ->with(['category:id,name_en,icon', 'poster:id,name'])
            ->latest('job_date')
            ->limit(20)
            ->get();

        return view('admin.jobs.workers.show', compact('worker', 'categories', 'stats', 'jobHistory'));
    }

    /**
     * Verify a worker.
     */
    public function verify(JobWorker $worker): RedirectResponse
    {
        $worker->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        return back()->with('success', 'Worker verified successfully');
    }

    /**
     * Remove worker verification.
     */
    public function unverify(JobWorker $worker): RedirectResponse
    {
        $worker->update([
            'is_verified' => false,
            'verified_at' => null,
        ]);

        return back()->with('success', 'Worker verification removed');
    }

    /**
     * Toggle availability.
     */
    public function toggleAvailability(JobWorker $worker): RedirectResponse
    {
        $worker->update(['is_available' => !$worker->is_available]);

        $status = $worker->is_available ? 'available' : 'unavailable';
        return back()->with('success', "Worker marked as {$status}");
    }

    /**
     * Update worker details.
     */
    public function update(Request $request, JobWorker $worker): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'vehicle_type' => 'nullable|string',
        ]);

        $worker->update($validated);

        return back()->with('success', 'Worker updated successfully');
    }

    /**
     * Export workers to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = JobWorker::with(['user:id,name,phone']);

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('is_available', true),
                'verified' => $query->where('is_verified', true),
                default => null,
            };
        }

        $workers = $query->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="workers-' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($workers) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Name', 'Phone', 'Status', 'Verified', 'Rating',
                'Jobs Completed', 'Total Earnings', 'Vehicle Type', 'Registered At',
            ]);

            foreach ($workers as $worker) {
                fputcsv($handle, [
                    $worker->id,
                    $worker->name,
                    $worker->user?->phone,
                    $worker->is_available ? 'Active' : 'Inactive',
                    $worker->is_verified ? 'Yes' : 'No',
                    $worker->rating,
                    $worker->jobs_completed,
                    $worker->total_earnings,
                    $worker->vehicle_type?->value ?? 'none',
                    $worker->created_at->format('Y-m-d H:i'),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
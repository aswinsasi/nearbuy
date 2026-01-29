<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\JobStatus;
use App\Models\JobCategory;
use App\Models\JobPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin controller for managing job posts.
 */
class JobPostController extends Controller
{
    /**
     * List all jobs.
     */
    public function index(Request $request): View
    {
        $query = JobPost::with([
            'poster:id,name,phone',
            'category:id,name_en,icon',
            'assignedWorker:id,name',
        ]);

        // Status filter
        if ($request->filled('status')) {
            $status = JobStatus::tryFrom($request->status);
            if ($status) {
                $query->where('status', $status);
            }
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->where('job_category_id', $request->category_id);
        }

        // Date filters
        if ($request->filled('from_date')) {
            $query->whereDate('job_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('job_date', '<=', $request->to_date);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('job_number', 'like', "%{$search}%");
            });
        }

        $jobs = $query->latest()->paginate(20)->withQueryString();
        $categories = JobCategory::where('is_active', true)->orderBy('name_en')->get();

        return view('admin.jobs.posts.index', compact('jobs', 'categories'));
    }

    /**
     * Show job details.
     */
    public function show(JobPost $job): View
    {
        $job->load([
            'poster:id,name,phone,email',
            'category:id,name_en,icon,description',
            'assignedWorker:id,name,rating,jobs_completed',
            'assignedWorker.user:id,phone',
            'applications' => fn($q) => $q->with('worker:id,name,rating')->latest(),
            'verification',
        ]);

        // Timeline events
        $timeline = $this->buildTimeline($job);

        // Poster's other jobs
        $posterJobs = JobPost::where('poster_user_id', $job->poster_user_id)
            ->where('id', '!=', $job->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'title', 'pay_amount', 'status', 'created_at']);

        return view('admin.jobs.posts.show', compact('job', 'timeline', 'posterJobs'));
    }

    /**
     * Build job timeline.
     */
    protected function buildTimeline(JobPost $job): array
    {
        $timeline = [];

        $timeline[] = [
            'event' => 'Job Created',
            'timestamp' => $job->created_at,
            'icon' => '📝',
        ];

        if ($job->posted_at) {
            $timeline[] = [
                'event' => 'Job Posted',
                'timestamp' => $job->posted_at,
                'icon' => '🚀',
            ];
        }

        if ($job->assigned_at && $job->assignedWorker) {
            $timeline[] = [
                'event' => "Assigned to {$job->assignedWorker->name}",
                'timestamp' => $job->assigned_at,
                'icon' => '👷',
            ];
        }

        if ($job->started_at) {
            $timeline[] = [
                'event' => 'Work Started',
                'timestamp' => $job->started_at,
                'icon' => '▶️',
            ];
        }

        if ($job->completed_at) {
            $timeline[] = [
                'event' => 'Job Completed',
                'timestamp' => $job->completed_at,
                'icon' => '✅',
            ];
        }

        if ($job->status === JobStatus::CANCELLED) {
            $timeline[] = [
                'event' => 'Job Cancelled',
                'timestamp' => $job->updated_at,
                'icon' => '❌',
            ];
        }

        usort($timeline, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        return $timeline;
    }

    /**
     * Cancel a job.
     */
    public function cancel(JobPost $job): RedirectResponse
    {
        if ($job->status === JobStatus::COMPLETED) {
            return back()->with('error', 'Cannot cancel a completed job');
        }

        $job->update(['status' => JobStatus::CANCELLED]);
        $job->applications()->where('status', 'pending')->update(['status' => 'withdrawn']);

        return back()->with('success', 'Job cancelled');
    }

    /**
     * Reopen a cancelled job.
     */
    public function reopen(JobPost $job): RedirectResponse
    {
        if ($job->status !== JobStatus::CANCELLED) {
            return back()->with('error', 'Only cancelled jobs can be reopened');
        }

        $job->update([
            'status' => JobStatus::OPEN,
            'posted_at' => now(),
            'expires_at' => now()->addHours(48),
        ]);

        return back()->with('success', 'Job reopened');
    }

    /**
     * Mark job as completed.
     */
    public function complete(JobPost $job): RedirectResponse
    {
        if ($job->status !== JobStatus::IN_PROGRESS) {
            return back()->with('error', 'Only in-progress jobs can be completed');
        }

        $job->update([
            'status' => JobStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        if ($job->assignedWorker) {
            $job->assignedWorker->increment('jobs_completed');
            $job->assignedWorker->increment('total_earnings', $job->pay_amount);
        }

        return back()->with('success', 'Job marked as completed');
    }

    /**
     * Unassign worker from job.
     */
    public function unassign(JobPost $job): RedirectResponse
    {
        if (!in_array($job->status, [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])) {
            return back()->with('error', 'Cannot unassign worker from this job');
        }

        $job->update([
            'status' => JobStatus::OPEN,
            'assigned_worker_id' => null,
            'assigned_at' => null,
            'started_at' => null,
        ]);

        return back()->with('success', 'Worker unassigned, job reopened');
    }

    /**
     * Export jobs to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = JobPost::with(['poster:id,name,phone', 'category:id,name_en', 'assignedWorker:id,name']);

        if ($request->filled('status')) {
            $status = JobStatus::tryFrom($request->status);
            if ($status) {
                $query->where('status', $status);
            }
        }

        $jobs = $query->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="jobs-' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($jobs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Job Number', 'Title', 'Category', 'Status', 'Amount',
                'Poster', 'Worker', 'Location', 'Job Date', 'Created At',
            ]);

            foreach ($jobs as $job) {
                fputcsv($handle, [
                    $job->id,
                    $job->job_number,
                    $job->title,
                    $job->category?->name_en,
                    $job->status->value,
                    $job->pay_amount,
                    $job->poster?->name,
                    $job->assignedWorker?->name,
                    $job->location_name,
                    $job->job_date?->format('Y-m-d'),
                    $job->created_at->format('Y-m-d H:i'),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Show job statistics.
     */
    public function stats(): View
    {
        $stats = [
            'by_status' => JobPost::select('status')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'avg_pay' => JobPost::where('status', JobStatus::COMPLETED)->avg('pay_amount') ?? 0,
            'total_value' => JobPost::where('status', JobStatus::COMPLETED)->sum('pay_amount'),
        ];

        return view('admin.jobs.posts.stats', compact('stats'));
    }
}
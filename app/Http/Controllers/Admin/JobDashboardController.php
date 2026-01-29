<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\WorkerBadge;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin dashboard controller for Jobs module (Njaanum Panikkar).
 */
class JobDashboardController extends Controller
{
    /**
     * Display Jobs module dashboard.
     */
    public function index(): View
    {
        $today = today();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $stats = [
            'workers' => [
                'total' => JobWorker::count(),
                'verified' => JobWorker::where('is_verified', true)->count(),
                'pending_verification' => JobWorker::where('is_verified', false)->count(),
                'active' => JobWorker::where('is_available', true)->count(),
                'with_vehicle' => JobWorker::where('vehicle_type', '!=', 'none')->count(),
            ],
            'jobs' => [
                'total' => JobPost::count(),
                'open' => JobPost::where('status', JobStatus::OPEN)->count(),
                'assigned' => JobPost::where('status', JobStatus::ASSIGNED)->count(),
                'in_progress' => JobPost::where('status', JobStatus::IN_PROGRESS)->count(),
                'completed' => JobPost::where('status', JobStatus::COMPLETED)->count(),
                'cancelled' => JobPost::where('status', JobStatus::CANCELLED)->count(),
                'today' => JobPost::whereDate('created_at', $today)->count(),
            ],
            'applications' => [
                'total' => JobApplication::count(),
                'pending' => JobApplication::where('status', 'pending')->count(),
                'accepted' => JobApplication::where('status', 'accepted')->count(),
            ],
            'earnings' => [
                'total' => JobPost::where('status', JobStatus::COMPLETED)->sum('pay_amount'),
                'this_week' => JobPost::where('status', JobStatus::COMPLETED)
                    ->where('completed_at', '>=', $weekStart)->sum('pay_amount'),
                'this_month' => JobPost::where('status', JobStatus::COMPLETED)
                    ->where('completed_at', '>=', $monthStart)->sum('pay_amount'),
            ],
        ];

        $recentJobs = JobPost::with(['poster:id,name', 'category:id,name_en,icon'])
            ->latest()
            ->limit(5)
            ->get();

        $recentWorkers = JobWorker::with('user:id,phone,name')
            ->latest()
            ->limit(5)
            ->get();

        $topCategories = JobCategory::withCount('jobPosts')
            ->orderByDesc('job_posts_count')
            ->limit(5)
            ->get();

        return view('admin.jobs.dashboard', compact('stats', 'recentJobs', 'recentWorkers', 'topCategories'));
    }
}
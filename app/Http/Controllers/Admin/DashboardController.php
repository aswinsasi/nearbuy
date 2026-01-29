<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Offer;
use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Shop;
use App\Models\User;
use App\Models\JobWorker;
use App\Models\JobPost;
use App\Models\JobCategory;
use App\Enums\JobStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard controller.
 */
class DashboardController extends Controller
{
    /**
     * Show dashboard.
     */
    public function index()
    {
        $stats = $this->getStats();
        $charts = $this->getChartData();
        $recentActivity = $this->getRecentActivity();

        // Job module stats
        $jobStats = $this->getJobStats();
        $jobCharts = $this->getJobChartData();
        $jobRecentActivity = $this->getJobRecentActivity();

        return view('admin.dashboard', compact(
            'stats',
            'charts',
            'recentActivity',
            'jobStats',
            'jobCharts',
            'jobRecentActivity'
        ));
    }

    /**
     * Get dashboard statistics.
     */
    protected function getStats(): array
    {
        return [
            'total_users' => User::count(),
            'customers' => User::whereDoesntHave('shop')->count(),
            'shop_owners' => User::whereHas('shop')->count(),
            'total_shops' => Shop::count(),
            'verified_shops' => Shop::where('verified', true)->count(),
            'active_offers' => Offer::active()->count(),
            'requests_today' => ProductRequest::whereDate('created_at', today())->count(),
            'requests_this_week' => ProductRequest::whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ])->count(),
            'responses_today' => ProductResponse::whereDate('created_at', today())->count(),
            'agreements_this_month' => Agreement::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'pending_agreements' => Agreement::where('status', 'pending')->count(),
            'total_agreements_value' => Agreement::where('status', 'confirmed')->sum('amount'),
        ];
    }

    /**
     * Get chart data.
     */
    protected function getChartData(): array
    {
        // Users registered per day (last 30 days)
        $usersByDay = User::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Requests per day (last 14 days)
        $requestsByDay = ProductRequest::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Shops by category
        $shopsByCategory = Shop::select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        // Agreements by status
        $agreementsByStatus = Agreement::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Fill missing dates for line charts
        $last30Days = collect();
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last30Days[$date] = $usersByDay[$date] ?? 0;
        }

        $last14Days = collect();
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last14Days[$date] = $requestsByDay[$date] ?? 0;
        }

        return [
            'users' => [
                'labels' => $last30Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last30Days->values()->toArray(),
            ],
            'requests' => [
                'labels' => $last14Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last14Days->values()->toArray(),
            ],
            'categories' => [
                'labels' => array_keys($shopsByCategory),
                'data' => array_values($shopsByCategory),
            ],
            'agreements' => [
                'labels' => array_keys($agreementsByStatus),
                'data' => array_values($agreementsByStatus),
            ],
        ];
    }

    /**
     * Get recent activity.
     */
    protected function getRecentActivity(): array
    {
        return [
            'recent_users' => User::with('shop')
                ->latest()
                ->take(5)
                ->get(),
            'recent_requests' => ProductRequest::with('user')
                ->latest()
                ->take(5)
                ->get(),
            'recent_agreements' => Agreement::with('creator')
                ->latest()
                ->take(5)
                ->get(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Job Module Stats Methods (Njaanum Panikkar)
    |--------------------------------------------------------------------------
    */

    /**
     * Get job module statistics.
     */
    protected function getJobStats(): array
    {
        $now = Carbon::now();

        // Workers stats
        $totalWorkers = JobWorker::count();
        $verifiedWorkers = JobWorker::where('is_verified', true)->count();
        $availableWorkers = JobWorker::where('is_available', true)
            ->where('is_verified', true)
            ->count();

        // Jobs stats
        $totalJobs = JobPost::count();
        $openJobs = JobPost::where('status', JobStatus::OPEN)->count();
        $assignedJobs = JobPost::where('status', JobStatus::ASSIGNED)->count();
        $jobsToday = JobPost::whereDate('created_at', today())->count();
        $jobsThisWeek = JobPost::whereBetween('created_at', [
            $now->copy()->startOfWeek(),
            $now->copy()->endOfWeek()
        ])->count();
        $jobsThisMonth = JobPost::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        // Completed/Cancelled stats
        $completedThisMonth = JobPost::where('status', JobStatus::COMPLETED)
            ->whereMonth('completed_at', $now->month)
            ->whereYear('completed_at', $now->year)
            ->count();
        $cancelledJobs = JobPost::where('status', JobStatus::CANCELLED)->count();

        // Completion rate calculation
        $completionRate = $this->calculateCompletionRate();

        return [
            'total_workers' => $totalWorkers,
            'verified_workers' => $verifiedWorkers,
            'available_workers' => $availableWorkers,
            'total_jobs' => $totalJobs,
            'open_jobs' => $openJobs,
            'assigned_jobs' => $assignedJobs,
            'jobs_today' => $jobsToday,
            'jobs_this_week' => $jobsThisWeek,
            'jobs_this_month' => $jobsThisMonth,
            'completed_this_month' => $completedThisMonth,
            'cancelled_jobs' => $cancelledJobs,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * Calculate job completion rate.
     */
    protected function calculateCompletionRate(): float
    {
        $completed = JobPost::where('status', JobStatus::COMPLETED)->count();
        $total = JobPost::whereIn('status', [
            JobStatus::COMPLETED,
            JobStatus::CANCELLED
        ])->count();

        if ($total === 0) {
            return 0;
        }

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Get job module chart data.
     */
    protected function getJobChartData(): array
    {
        // Job posts per day (last 14 days)
        $postsByDay = JobPost::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        $last14Days = collect();
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last14Days[$date] = $postsByDay[$date] ?? 0;
        }

        // Workers by category (using job_types JSON field)
        $categoryWorkerCounts = [];
        $categories = JobCategory::active()->get();
        foreach ($categories as $category) {
            $count = JobWorker::whereJsonContains('job_types', $category->id)->count();
            if ($count > 0) {
                $categoryWorkerCounts[$category->name_en] = $count;
            }
        }
        arsort($categoryWorkerCounts);
        $categoryWorkerCounts = array_slice($categoryWorkerCounts, 0, 10, true);

        // Jobs by status
        $jobStatuses = JobPost::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Format status labels
        $formattedStatuses = [];
        foreach ($jobStatuses as $status => $count) {
            $label = ucfirst(str_replace('_', ' ', $status));
            $formattedStatuses[$label] = $count;
        }

        // Worker registrations per day (last 30 days)
        $workersByDay = JobWorker::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        $last30Days = collect();
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $last30Days[$date] = $workersByDay[$date] ?? 0;
        }

        return [
            'posts' => [
                'labels' => $last14Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last14Days->values()->toArray(),
            ],
            'workers_category' => [
                'labels' => array_keys($categoryWorkerCounts),
                'data' => array_values($categoryWorkerCounts),
            ],
            'status' => [
                'labels' => array_keys($formattedStatuses),
                'data' => array_values($formattedStatuses),
            ],
            'worker_registrations' => [
                'labels' => $last30Days->keys()->map(fn($d) => date('M j', strtotime($d)))->toArray(),
                'data' => $last30Days->values()->toArray(),
            ],
        ];
    }

    /**
     * Get job module recent activity.
     */
    protected function getJobRecentActivity(): array
    {
        return [
            'recent_workers' => JobWorker::with(['user'])
                ->latest()
                ->take(5)
                ->get(),
            'recent_jobs' => JobPost::with(['poster', 'category', 'assignedWorker'])
                ->latest()
                ->take(5)
                ->get(),
            'top_categories' => JobCategory::withCount(['jobPosts as jobs_count'])
                ->where('is_active', true)
                ->orderByDesc('jobs_count')
                ->take(5)
                ->get(),
        ];
    }
}
@extends('admin.layouts.app')

@section('title', 'Dashboard')

@section('content')
<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Users -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-blue-100 rounded-lg">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Users</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['total_users']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-gray-500">{{ $stats['customers'] }} customers</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-gray-500">{{ $stats['shop_owners'] }} shop owners</span>
        </div>
    </div>

    <!-- Shops -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-emerald-100 rounded-lg">
                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Shops</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['total_shops']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-emerald-600">{{ $stats['verified_shops'] }} verified</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-gray-500">{{ $stats['active_offers'] }} active offers</span>
        </div>
    </div>

    <!-- Requests Today -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-yellow-100 rounded-lg">
                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Requests Today</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['requests_today']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-gray-500">{{ $stats['requests_this_week'] }} this week</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-gray-500">{{ $stats['responses_today'] }} responses</span>
        </div>
    </div>

    <!-- Agreements -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-purple-100 rounded-lg">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Agreements (Month)</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['agreements_this_month']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-yellow-600">{{ $stats['pending_agreements'] }} pending</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-gray-500">₹{{ number_format($stats['total_agreements_value']) }}</span>
        </div>
    </div>
</div>

<!-- Jobs Module Stats (Njaanum Panikkar) -->
@if(isset($jobStats))
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-800 flex items-center">
            <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            Njaanum Panikkar (Jobs)
        </h2>
        <a href="{{ route('admin.jobs.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-700">View Full Dashboard →</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Workers -->
        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-indigo-500">
            <div class="flex items-center">
                <div class="p-3 bg-indigo-100 rounded-lg">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Workers</p>
                    <p class="text-2xl font-semibold text-gray-800">{{ number_format($jobStats['total_workers']) }}</p>
                </div>
            </div>
            <div class="mt-4 flex text-sm">
                <span class="text-green-600">{{ $jobStats['verified_workers'] }} verified</span>
                <span class="mx-2 text-gray-300">•</span>
                <span class="text-gray-500">{{ $jobStats['available_workers'] }} available</span>
            </div>
        </div>

        <!-- Total Job Posts -->
        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-orange-500">
            <div class="flex items-center">
                <div class="p-3 bg-orange-100 rounded-lg">
                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Job Posts</p>
                    <p class="text-2xl font-semibold text-gray-800">{{ number_format($jobStats['total_jobs']) }}</p>
                </div>
            </div>
            <div class="mt-4 flex text-sm">
                <span class="text-blue-600">{{ $jobStats['open_jobs'] }} open</span>
                <span class="mx-2 text-gray-300">•</span>
                <span class="text-gray-500">{{ $jobStats['assigned_jobs'] }} assigned</span>
            </div>
        </div>

        <!-- Jobs Today -->
        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-teal-500">
            <div class="flex items-center">
                <div class="p-3 bg-teal-100 rounded-lg">
                    <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Jobs Today</p>
                    <p class="text-2xl font-semibold text-gray-800">{{ number_format($jobStats['jobs_today']) }}</p>
                </div>
            </div>
            <div class="mt-4 flex text-sm">
                <span class="text-gray-500">{{ $jobStats['jobs_this_week'] }} this week</span>
                <span class="mx-2 text-gray-300">•</span>
                <span class="text-gray-500">{{ $jobStats['jobs_this_month'] }} this month</span>
            </div>
        </div>

        <!-- Completed Jobs -->
        <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-green-500">
            <div class="flex items-center">
                <div class="p-3 bg-green-100 rounded-lg">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Completed (Month)</p>
                    <p class="text-2xl font-semibold text-gray-800">{{ number_format($jobStats['completed_this_month']) }}</p>
                </div>
            </div>
            <div class="mt-4 flex text-sm">
                <span class="text-red-600">{{ $jobStats['cancelled_jobs'] }} cancelled</span>
                <span class="mx-2 text-gray-300">•</span>
                <span class="text-gray-500">{{ $jobStats['completion_rate'] }}% rate</span>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Users Chart -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">User Registrations (30 Days)</h3>
        <canvas id="usersChart" height="200"></canvas>
    </div>

    <!-- Requests Chart -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Product Requests (14 Days)</h3>
        <canvas id="requestsChart" height="200"></canvas>
    </div>

    <!-- Categories Pie -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Shops by Category</h3>
        <canvas id="categoriesChart" height="200"></canvas>
    </div>

    <!-- Agreements Status -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Agreements by Status</h3>
        <canvas id="agreementsChart" height="200"></canvas>
    </div>
</div>

<!-- Jobs Charts -->
@if(isset($jobCharts))
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Job Posts Chart -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Job Posts (14 Days)</h3>
        <canvas id="jobPostsChart" height="200"></canvas>
    </div>

    <!-- Workers by Category -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Workers by Category</h3>
        <canvas id="workersCategoryChart" height="200"></canvas>
    </div>

    <!-- Job Status Distribution -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Jobs by Status</h3>
        <canvas id="jobStatusChart" height="200"></canvas>
    </div>

    <!-- Worker Registrations -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Worker Registrations (30 Days)</h3>
        <canvas id="workerRegistrationsChart" height="200"></canvas>
    </div>
</div>
@endif

<!-- Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Recent Users -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Users</h3>
        <div class="space-y-4">
            @forelse($recentActivity['recent_users'] as $user)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                            <span class="text-gray-600 font-medium">{{ substr($user->name, 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-800">{{ $user->name }}</p>
                            <p class="text-xs text-gray-500">{{ $user->shop ? 'Shop Owner' : 'Customer' }}</p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400">{{ $user->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent users</p>
            @endforelse
        </div>
        <a href="{{ route('admin.users.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all users →</a>
    </div>

    <!-- Recent Requests -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Requests</h3>
        <div class="space-y-4">
            @forelse($recentActivity['recent_requests'] as $request)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">{{ Str::limit($request->description, 30) }}</p>
                        <p class="text-xs text-gray-500">{{ $request->user->name ?? 'Unknown' }}</p>
                    </div>
                    <span class="text-xs text-gray-400">{{ $request->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent requests</p>
            @endforelse
        </div>
        <a href="{{ route('admin.requests.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all requests →</a>
    </div>

    <!-- Recent Agreements -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Agreements</h3>
        <div class="space-y-4">
            @forelse($recentActivity['recent_agreements'] as $agreement)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">₹{{ number_format($agreement->amount) }}</p>
                        <p class="text-xs text-gray-500">{{ $agreement->creator->name ?? 'Unknown' }}</p>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full {{ $agreement->status->value === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($agreement->status->value) }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent agreements</p>
            @endforelse
        </div>
        <a href="{{ route('admin.agreements.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all agreements →</a>
    </div>
</div>

<!-- Jobs Recent Activity -->
@if(isset($jobRecentActivity))
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Workers -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Recent Workers
        </h3>
        <div class="space-y-4">
            @forelse($jobRecentActivity['recent_workers'] as $worker)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center overflow-hidden">
                            @if($worker->photo_url)
                                <img src="{{ $worker->photo_url }}" class="w-10 h-10 rounded-full object-cover" alt="">
                            @else
                                <span class="text-indigo-600 font-medium">{{ substr($worker->name, 0, 1) }}</span>
                            @endif
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-800">{{ $worker->name }}</p>
                            <p class="text-xs text-gray-500">{{ $worker->vehicle_type->display() ?? 'No vehicle' }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        @if($worker->is_verified)
                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Verified</span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">Pending</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent workers</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.workers.index') }}" class="block mt-4 text-sm text-indigo-600 hover:text-indigo-700">View all workers →</a>
    </div>

    <!-- Recent Job Posts -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Recent Job Posts
        </h3>
        <div class="space-y-4">
            @forelse($jobRecentActivity['recent_jobs'] as $job)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">{{ Str::limit($job->title, 25) }}</p>
                        <p class="text-xs text-gray-500">{{ $job->poster->name ?? 'Unknown' }} • {{ $job->category->name_en ?? 'N/A' }}</p>
                    </div>
                    @php
                        $statusValue = $job->status instanceof \App\Enums\JobStatus ? $job->status->value : $job->status;
                    @endphp
                    <span class="px-2 py-1 text-xs rounded-full 
                        @switch($statusValue)
                            @case('open') bg-blue-100 text-blue-700 @break
                            @case('assigned') bg-yellow-100 text-yellow-700 @break
                            @case('in_progress') bg-purple-100 text-purple-700 @break
                            @case('completed') bg-green-100 text-green-700 @break
                            @case('cancelled') bg-red-100 text-red-700 @break
                            @case('expired') bg-gray-100 text-gray-700 @break
                            @default bg-gray-100 text-gray-700
                        @endswitch
                    ">
                        {{ ucfirst(str_replace('_', ' ', $statusValue)) }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent job posts</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.posts.index') }}" class="block mt-4 text-sm text-orange-600 hover:text-orange-700">View all job posts →</a>
    </div>

    <!-- Jobs by Category Summary -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
            </svg>
            Top Job Categories
        </h3>
        <div class="space-y-3">
            @forelse($jobRecentActivity['top_categories'] as $category)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-teal-100 rounded-lg flex items-center justify-center mr-3">
                            @if($category->icon)
                                <span class="text-lg">{{ $category->icon }}</span>
                            @else
                                <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                </svg>
                            @endif
                        </div>
                        <span class="text-sm font-medium text-gray-800">{{ $category->name_en }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-sm font-semibold text-gray-800">{{ $category->jobs_count ?? 0 }}</span>
                        <span class="text-xs text-gray-500 ml-1">jobs</span>
                    </div>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No categories yet</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.categories.index') }}" class="block mt-4 text-sm text-teal-600 hover:text-teal-700">Manage categories →</a>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
// Users Chart
new Chart(document.getElementById('usersChart'), {
    type: 'line',
    data: {
        labels: @json($charts['users']['labels']),
        datasets: [{
            label: 'New Users',
            data: @json($charts['users']['data']),
            borderColor: '#3B82F6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Requests Chart
new Chart(document.getElementById('requestsChart'), {
    type: 'bar',
    data: {
        labels: @json($charts['requests']['labels']),
        datasets: [{
            label: 'Requests',
            data: @json($charts['requests']['data']),
            backgroundColor: '#F59E0B'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Categories Chart
new Chart(document.getElementById('categoriesChart'), {
    type: 'doughnut',
    data: {
        labels: @json($charts['categories']['labels']),
        datasets: [{
            data: @json($charts['categories']['data']),
            backgroundColor: [
                '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
                '#EC4899', '#6366F1', '#14B8A6', '#F97316', '#84CC16'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' }
        }
    }
});

// Agreements Chart
new Chart(document.getElementById('agreementsChart'), {
    type: 'pie',
    data: {
        labels: @json($charts['agreements']['labels']),
        datasets: [{
            data: @json($charts['agreements']['data']),
            backgroundColor: ['#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#6B7280']
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' }
        }
    }
});

@if(isset($jobCharts))
// Job Posts Chart
new Chart(document.getElementById('jobPostsChart'), {
    type: 'bar',
    data: {
        labels: @json($jobCharts['posts']['labels']),
        datasets: [{
            label: 'Job Posts',
            data: @json($jobCharts['posts']['data']),
            backgroundColor: '#6366F1'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Workers by Category Chart
@if(count($jobCharts['workers_category']['labels']) > 0)
new Chart(document.getElementById('workersCategoryChart'), {
    type: 'doughnut',
    data: {
        labels: @json($jobCharts['workers_category']['labels']),
        datasets: [{
            data: @json($jobCharts['workers_category']['data']),
            backgroundColor: [
                '#6366F1', '#8B5CF6', '#A855F7', '#D946EF', '#EC4899',
                '#F43F5E', '#F97316', '#FBBF24', '#84CC16', '#22C55E'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' }
        }
    }
});
@else
document.getElementById('workersCategoryChart').parentElement.innerHTML = '<h3 class="text-lg font-semibold text-gray-800 mb-4">Workers by Category</h3><p class="text-gray-500 text-center py-8">No worker data available</p>';
@endif

// Job Status Chart
@if(count($jobCharts['status']['labels']) > 0)
new Chart(document.getElementById('jobStatusChart'), {
    type: 'pie',
    data: {
        labels: @json($jobCharts['status']['labels']),
        datasets: [{
            data: @json($jobCharts['status']['data']),
            backgroundColor: ['#3B82F6', '#F59E0B', '#8B5CF6', '#10B981', '#EF4444', '#6B7280']
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' }
        }
    }
});
@else
document.getElementById('jobStatusChart').parentElement.innerHTML = '<h3 class="text-lg font-semibold text-gray-800 mb-4">Jobs by Status</h3><p class="text-gray-500 text-center py-8">No job data available</p>';
@endif

// Worker Registrations Chart
new Chart(document.getElementById('workerRegistrationsChart'), {
    type: 'line',
    data: {
        labels: @json($jobCharts['worker_registrations']['labels']),
        datasets: [{
            label: 'New Workers',
            data: @json($jobCharts['worker_registrations']['data']),
            borderColor: '#8B5CF6',
            backgroundColor: 'rgba(139, 92, 246, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
@endif
</script>
@endpush
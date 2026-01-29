@extends('admin.layouts.app')

@section('title', 'Jobs Dashboard - Njaanum Panikkar')

@section('content')
<!-- Page Header -->
<div class="mb-8">
    <div class="flex items-center justify-between">
        <div class="flex items-center">
            <div class="p-3 bg-indigo-100 rounded-xl mr-4">
                <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Jobs Marketplace Dashboard</h1>
                <p class="text-gray-500">Njaanum Panikkar Module Overview</p>
            </div>
        </div>
        <div class="flex space-x-3">
            <a href="{{ route('admin.jobs.workers.export') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Workers -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-indigo-100 rounded-lg">
                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Workers</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['workers']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-green-600">{{ $stats['workers']['verified'] }} verified</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-yellow-600">{{ $stats['workers']['pending_verification'] }} pending</span>
        </div>
    </div>

    <!-- Jobs -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-blue-100 rounded-lg">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Jobs</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['jobs']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-green-600">{{ $stats['jobs']['open'] }} open</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-blue-600">{{ $stats['jobs']['in_progress'] }} in progress</span>
        </div>
    </div>

    <!-- Applications -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-purple-100 rounded-lg">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Applications</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['applications']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-yellow-600">{{ $stats['applications']['pending'] }} pending</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-green-600">{{ $stats['applications']['accepted'] }} accepted</span>
        </div>
    </div>

    <!-- Earnings -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-emerald-100 rounded-lg">
                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total Facilitated</p>
                <p class="text-2xl font-semibold text-gray-800">₹{{ number_format($stats['earnings']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-gray-500">₹{{ number_format($stats['earnings']['this_week']) }} this week</span>
        </div>
    </div>
</div>

<!-- Job Status Breakdown -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    <div class="bg-green-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-green-600">{{ $stats['jobs']['open'] }}</p>
        <p class="text-sm text-green-700">Open</p>
    </div>
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-blue-600">{{ $stats['jobs']['assigned'] }}</p>
        <p class="text-sm text-blue-700">Assigned</p>
    </div>
    <div class="bg-yellow-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-yellow-600">{{ $stats['jobs']['in_progress'] }}</p>
        <p class="text-sm text-yellow-700">In Progress</p>
    </div>
    <div class="bg-emerald-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-emerald-600">{{ $stats['jobs']['completed'] }}</p>
        <p class="text-sm text-emerald-700">Completed</p>
    </div>
    <div class="bg-gray-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-gray-600">{{ $stats['jobs']['cancelled'] }}</p>
        <p class="text-sm text-gray-700">Cancelled</p>
    </div>
</div>

<!-- Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Recent Jobs -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Jobs</h3>
        <div class="space-y-4">
            @forelse($recentJobs as $job)
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">
                            {{ $job->category->icon ?? '📋' }} {{ Str::limit($job->title, 25) }}
                        </p>
                        <p class="text-xs text-gray-500">
                            ₹{{ number_format($job->pay_amount) }} • {{ $job->poster->name ?? 'Unknown' }}
                        </p>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full {{ $job->status->value === 'completed' ? 'bg-green-100 text-green-700' : ($job->status->value === 'open' ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">
                        {{ ucfirst($job->status->value) }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent jobs</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.posts.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all jobs →</a>
    </div>

    <!-- Recent Workers -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Workers</h3>
        <div class="space-y-4">
            @forelse($recentWorkers as $worker)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center">
                            <span class="text-indigo-600 font-medium">{{ substr($worker->name, 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-800">{{ $worker->name }}</p>
                            <p class="text-xs text-gray-500">{{ $worker->user?->phone }}</p>
                        </div>
                    </div>
                    @if($worker->is_verified)
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-full">Verified</span>
                    @else
                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-full">Pending</span>
                    @endif
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent workers</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.workers.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all workers →</a>
    </div>

    <!-- Top Categories -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Top Categories</h3>
        <div class="space-y-4">
            @forelse($topCategories as $category)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">{{ $category->icon ?? '📋' }}</span>
                        <p class="text-sm font-medium text-gray-800">{{ $category->name }}</p>
                    </div>
                    <span class="text-sm text-gray-500">{{ $category->job_posts_count }} jobs</span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No categories</p>
            @endforelse
        </div>
        <a href="{{ route('admin.jobs.categories.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">Manage categories →</a>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-xl shadow-sm p-6">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="{{ route('admin.jobs.workers.index', ['status' => 'pending']) }}" class="flex flex-col items-center p-4 bg-yellow-50 rounded-xl hover:bg-yellow-100 transition">
            <span class="text-2xl mb-2">⏳</span>
            <span class="text-sm font-medium text-yellow-800">Verify Workers</span>
            <span class="text-xs text-yellow-600">{{ $stats['workers']['pending_verification'] }} pending</span>
        </a>
        <a href="{{ route('admin.jobs.posts.index', ['status' => 'open']) }}" class="flex flex-col items-center p-4 bg-green-50 rounded-xl hover:bg-green-100 transition">
            <span class="text-2xl mb-2">🟢</span>
            <span class="text-sm font-medium text-green-800">Open Jobs</span>
            <span class="text-xs text-green-600">{{ $stats['jobs']['open'] }} available</span>
        </a>
        <a href="{{ route('admin.jobs.posts.index', ['status' => 'in_progress']) }}" class="flex flex-col items-center p-4 bg-blue-50 rounded-xl hover:bg-blue-100 transition">
            <span class="text-2xl mb-2">⏳</span>
            <span class="text-sm font-medium text-blue-800">In Progress</span>
            <span class="text-xs text-blue-600">{{ $stats['jobs']['in_progress'] }} active</span>
        </a>
        <a href="{{ route('admin.jobs.categories.index') }}" class="flex flex-col items-center p-4 bg-purple-50 rounded-xl hover:bg-purple-100 transition">
            <span class="text-2xl mb-2">🏷️</span>
            <span class="text-sm font-medium text-purple-800">Categories</span>
            <span class="text-xs text-purple-600">Manage</span>
        </a>
        <a href="{{ route('admin.jobs.workers.export') }}" class="flex flex-col items-center p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition">
            <span class="text-2xl mb-2">📥</span>
            <span class="text-sm font-medium text-gray-800">Export Workers</span>
            <span class="text-xs text-gray-600">CSV</span>
        </a>
        <a href="{{ route('admin.jobs.posts.export') }}" class="flex flex-col items-center p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition">
            <span class="text-2xl mb-2">📊</span>
            <span class="text-sm font-medium text-gray-800">Export Jobs</span>
            <span class="text-xs text-gray-600">CSV</span>
        </a>
    </div>
</div>
@endsection
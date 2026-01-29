@extends('admin.layouts.app')

@section('title', $worker->name . ' - Worker Details')

@section('content')
<!-- Page Header -->
<div class="mb-8 flex items-center justify-between">
    <div class="flex items-center">
        <a href="{{ route('admin.jobs.workers.index') }}" class="mr-4 p-2 hover:bg-gray-100 rounded-lg">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $worker->name }}</h1>
            <p class="text-gray-500">{{ $worker->user?->phone }}</p>
        </div>
    </div>
    <div class="flex space-x-3">
        @if(!$worker->is_verified)
            <form action="{{ route('admin.jobs.workers.verify', $worker) }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    ✓ Verify Worker
                </button>
            </form>
        @else
            <form action="{{ route('admin.jobs.workers.unverify', $worker) }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition">
                    Remove Verification
                </button>
            </form>
        @endif
        <form action="{{ route('admin.jobs.workers.toggle-availability', $worker) }}" method="POST">
            @csrf
            <button type="submit" class="px-4 py-2 {{ $worker->is_available ? 'bg-gray-600' : 'bg-blue-600' }} text-white rounded-lg hover:opacity-90 transition">
                {{ $worker->is_available ? 'Mark Inactive' : 'Mark Active' }}
            </button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
        {{ session('success') }}
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Info & Stats -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Worker Info Card -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Worker Information</h3>
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-gray-500">Name</p>
                    <p class="font-medium text-gray-900">{{ $worker->name }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Phone</p>
                    <p class="font-medium text-gray-900">{{ $worker->user?->phone }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Status</p>
                    <div class="flex items-center space-x-2 mt-1">
                        @if($worker->is_verified)
                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Verified</span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                        @endif
                        @if($worker->is_available)
                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">Active</span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">Inactive</span>
                        @endif
                    </div>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Vehicle</p>
                    <p class="font-medium text-gray-900">
                        {{ $worker->vehicle_type?->value !== 'none' ? ucfirst($worker->vehicle_type->value) : 'None' }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Address</p>
                    <p class="font-medium text-gray-900">{{ $worker->address ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Registered</p>
                    <p class="font-medium text-gray-900">{{ $worker->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>

            @if($categories->isNotEmpty())
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <p class="text-sm text-gray-500 mb-2">Job Categories</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($categories as $category)
                            <span class="px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm">
                                {{ $category->icon ?? '📋' }} {{ $category->name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-indigo-600">{{ $stats['total_jobs'] }}</p>
                <p class="text-sm text-gray-500">Total Jobs</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ $stats['completed_jobs'] }}</p>
                <p class="text-sm text-gray-500">Completed</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ $stats['active_jobs'] }}</p>
                <p class="text-sm text-gray-500">Active</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4 text-center">
                <p class="text-2xl font-bold text-emerald-600">₹{{ number_format($stats['total_earnings']) }}</p>
                <p class="text-sm text-gray-500">Total Earned</p>
            </div>
        </div>

        <!-- Job History -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Jobs</h3>
            <div class="space-y-4">
                @forelse($jobHistory as $job)
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <span class="text-2xl mr-3">{{ $job->category->icon ?? '📋' }}</span>
                            <div>
                                <a href="{{ route('admin.jobs.posts.show', $job) }}" class="text-sm font-medium text-gray-900 hover:text-indigo-600">
                                    {{ $job->title }}
                                </a>
                                <p class="text-xs text-gray-500">
                                    {{ $job->job_date?->format('M d, Y') }} • {{ $job->poster?->name }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-900">₹{{ number_format($job->pay_amount) }}</p>
                            <span class="px-2 py-1 text-xs rounded-full {{ $job->status->value === 'completed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' }}">
                                {{ ucfirst($job->status->value) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-center py-8">No job history</p>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Right Column: Rating & Badges -->
    <div class="space-y-6">
        <!-- Rating Card -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating & Performance</h3>
            <div class="text-center mb-6">
                <p class="text-5xl font-bold text-yellow-500">{{ number_format($worker->rating, 1) }}</p>
                <div class="flex justify-center mt-2">
                    @for($i = 1; $i <= 5; $i++)
                        <svg class="w-6 h-6 {{ $i <= $worker->rating ? 'text-yellow-400' : 'text-gray-300' }}" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                    @endfor
                </div>
                <p class="text-sm text-gray-500 mt-2">{{ $worker->rating_count }} reviews</p>
            </div>

            <div class="space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">This Week</span>
                    <span class="font-medium text-gray-900">₹{{ number_format($stats['this_week_earnings']) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Total Earnings</span>
                    <span class="font-medium text-gray-900">₹{{ number_format($stats['total_earnings']) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Jobs Completed</span>
                    <span class="font-medium text-gray-900">{{ $worker->jobs_completed }}</span>
                </div>
            </div>
        </div>

        <!-- Badges -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Badges</h3>
            @if($worker->badges && $worker->badges->count() > 0)
                <div class="grid grid-cols-2 gap-4">
                    @foreach($worker->badges as $badge)
                        <div class="text-center p-4 bg-gradient-to-br from-yellow-50 to-orange-50 rounded-xl">
                            <span class="text-3xl">{{ $badge->badge_type->icon() }}</span>
                            <p class="text-sm font-medium text-gray-800 mt-2">{{ $badge->badge_type->label() }}</p>
                            <p class="text-xs text-gray-500">{{ $badge->earned_at->format('M d, Y') }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-gray-500 text-center py-4">No badges earned yet</p>
            @endif
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions</h3>
            <div class="space-y-3">
                <a href="{{ route('admin.jobs.posts.index', ['worker_id' => $worker->id]) }}" class="block w-full px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100 transition text-center">
                    View All Jobs
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
@extends('admin.layouts.app')

@section('title', $category->name_en . ' - Job Category')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.jobs.categories.index') }}" class="text-indigo-600 hover:text-indigo-700 flex items-center gap-1 text-sm mb-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Back to Categories
    </a>
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-4xl">{{ $category->icon }}</span>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $category->name_en }}</h1>
                <p class="text-gray-600">{{ $category->name_ml }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if($category->is_active)
                <span class="px-3 py-1 text-sm rounded-full bg-green-100 text-green-700">Active</span>
            @else
                <span class="px-3 py-1 text-sm rounded-full bg-gray-100 text-gray-600">Inactive</span>
            @endif
            <form action="{{ route('admin.jobs.categories.toggle-active', $category) }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="px-4 py-2 {{ $category->is_active ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }} rounded-lg transition">
                    {{ $category->is_active ? 'Deactivate' : 'Activate' }}
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow-sm p-6">
        <p class="text-sm font-medium text-gray-500">Total Jobs</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($category->job_posts_count) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-6">
        <p class="text-sm font-medium text-gray-500">Open Jobs</p>
        <p class="text-3xl font-bold text-blue-600 mt-1">{{ number_format($category->open_jobs_count) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-6">
        <p class="text-sm font-medium text-gray-500">Completed Jobs</p>
        <p class="text-3xl font-bold text-green-600 mt-1">{{ number_format($category->completed_jobs_count) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-6">
        <p class="text-sm font-medium text-gray-500">Workers</p>
        <p class="text-3xl font-bold text-indigo-600 mt-1">{{ $workers->count() }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Category Details -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Category Details</h3>
        <dl class="space-y-4">
            <div>
                <dt class="text-sm font-medium text-gray-500">Tier</dt>
                <dd class="mt-1">
                    @if($category->tier === 1)
                        <span class="px-2 py-1 text-sm rounded-full bg-green-100 text-green-700">Tier 1 - Zero Skills</span>
                    @else
                        <span class="px-2 py-1 text-sm rounded-full bg-blue-100 text-blue-700">Tier 2 - Basic Skills</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Typical Pay Range</dt>
                <dd class="mt-1 text-gray-900">
                    @if($category->typical_pay_min || $category->typical_pay_max)
                        ₹{{ number_format($category->typical_pay_min ?? 0) }} - ₹{{ number_format($category->typical_pay_max ?? 0) }}
                    @else
                        <span class="text-gray-400">Not set</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Typical Duration</dt>
                <dd class="mt-1 text-gray-900">
                    @if($category->typical_duration_hours)
                        {{ $category->typical_duration_hours }} hours
                    @else
                        <span class="text-gray-400">Not set</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Requirements</dt>
                <dd class="mt-1">
                    @if($category->requires_vehicle)
                        <span class="px-2 py-1 text-sm rounded-full bg-yellow-100 text-yellow-700">🚗 Vehicle Required</span>
                    @else
                        <span class="text-gray-500">No special requirements</span>
                    @endif
                </dd>
            </div>
            @if($category->description)
            <div>
                <dt class="text-sm font-medium text-gray-500">Description</dt>
                <dd class="mt-1 text-gray-900">{{ $category->description }}</dd>
            </div>
            @endif
            <div>
                <dt class="text-sm font-medium text-gray-500">Slug</dt>
                <dd class="mt-1 text-gray-500 font-mono text-sm">{{ $category->slug }}</dd>
            </div>
        </dl>
    </div>

    <!-- Price Statistics (Last 30 Days) -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Price Stats (30 Days)</h3>
        @if($priceStats && ($priceStats->min || $priceStats->max || $priceStats->avg))
            <dl class="space-y-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Minimum Paid</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">₹{{ number_format($priceStats->min ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Maximum Paid</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">₹{{ number_format($priceStats->max ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Average Paid</dt>
                    <dd class="mt-1 text-2xl font-semibold text-indigo-600">₹{{ number_format($priceStats->avg ?? 0) }}</dd>
                </div>
            </dl>
        @else
            <p class="text-gray-500 text-center py-8">No completed jobs in last 30 days</p>
        @endif
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
        <div class="space-y-3">
            <a href="{{ route('admin.jobs.posts.index', ['category' => $category->id]) }}" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <span class="text-gray-700">View All Jobs</span>
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            <a href="{{ route('admin.jobs.workers.index', ['category' => $category->id]) }}" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <span class="text-gray-700">View Workers</span>
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            <form action="{{ route('admin.jobs.categories.destroy', $category) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this category?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full flex items-center justify-between p-3 bg-red-50 rounded-lg hover:bg-red-100 transition text-red-700">
                    <span>Delete Category</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Recent Jobs -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">Recent Jobs</h3>
        <a href="{{ route('admin.jobs.posts.index', ['category' => $category->id]) }}" class="text-sm text-indigo-600 hover:text-indigo-700">View All →</a>
    </div>
    @if($recentJobs->count() > 0)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Poster</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pay</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($recentJobs as $job)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-gray-900">{{ Str::limit($job->title, 30) }}</div>
                                <div class="text-xs text-gray-500">{{ $job->job_number }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                {{ $job->poster->name ?? 'Unknown' }}
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                ₹{{ number_format($job->pay_amount) }}
                            </td>
                            <td class="px-4 py-3">
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
                                        @default bg-gray-100 text-gray-700
                                    @endswitch
                                ">
                                    {{ ucfirst(str_replace('_', ' ', $statusValue)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                {{ $job->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.jobs.posts.show', $job) }}" class="text-indigo-600 hover:text-indigo-900 text-sm">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-gray-500 text-center py-8">No jobs in this category yet</p>
    @endif
</div>

<!-- Workers in this Category -->
<div class="bg-white rounded-xl shadow-sm p-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">Workers ({{ $workers->count() }})</h3>
        <a href="{{ route('admin.jobs.workers.index', ['category' => $category->id]) }}" class="text-sm text-indigo-600 hover:text-indigo-700">View All →</a>
    </div>
    @if($workers->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($workers as $worker)
                <div class="border border-gray-200 rounded-lg p-4 hover:border-indigo-300 transition">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center">
                            <span class="text-indigo-600 font-medium">{{ substr($worker->name, 0, 1) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $worker->name }}</p>
                            <div class="flex items-center text-xs text-gray-500">
                                <span>⭐ {{ number_format($worker->rating, 1) }}</span>
                                <span class="mx-1">•</span>
                                <span>{{ $worker->jobs_completed }} jobs</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-gray-500 text-center py-8">No workers registered for this category</p>
    @endif
</div>
@endsection
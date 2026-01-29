@extends('admin.layouts.app')

@section('title', $job->title . ' - Job Details')

@section('content')
<!-- Page Header -->
<div class="mb-8 flex items-center justify-between">
    <div class="flex items-center">
        <a href="{{ route('admin.jobs.posts.index') }}" class="mr-4 p-2 hover:bg-gray-100 rounded-lg">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <div class="flex items-center">
                <span class="text-2xl mr-2">{{ $job->category->icon ?? '📋' }}</span>
                <h1 class="text-2xl font-bold text-gray-800">{{ $job->title }}</h1>
            </div>
            <p class="text-gray-500">{{ $job->job_number }}</p>
        </div>
    </div>
    <div class="flex space-x-3">
        @if($job->status->value === 'in_progress')
            <form action="{{ route('admin.jobs.posts.complete', $job) }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    ✓ Mark Complete
                </button>
            </form>
        @endif
        @if(in_array($job->status->value, ['assigned', 'in_progress']))
            <form action="{{ route('admin.jobs.posts.unassign', $job) }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition">
                    Unassign Worker
                </button>
            </form>
        @endif
        @if(!in_array($job->status->value, ['completed', 'cancelled']))
            <form action="{{ route('admin.jobs.posts.cancel', $job) }}" method="POST" onsubmit="return confirm('Cancel this job?')">
                @csrf
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    Cancel Job
                </button>
            </form>
        @endif
        @if($job->status->value === 'cancelled')
            <form action="{{ route('admin.jobs.posts.reopen', $job) }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    Reopen Job
                </button>
            </form>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
        {{ session('error') }}
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Job Details -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Status Banner -->
        @php
            $statusBg = [
                'draft' => 'bg-gray-100 border-gray-300',
                'open' => 'bg-green-50 border-green-300',
                'assigned' => 'bg-blue-50 border-blue-300',
                'in_progress' => 'bg-yellow-50 border-yellow-300',
                'completed' => 'bg-emerald-50 border-emerald-300',
                'cancelled' => 'bg-red-50 border-red-300',
                'expired' => 'bg-gray-50 border-gray-300',
            ];
        @endphp
        <div class="p-4 rounded-xl border-2 {{ $statusBg[$job->status->value] ?? 'bg-gray-50 border-gray-300' }}">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <span class="text-2xl mr-3">{{ $job->status->emoji() }}</span>
                    <div>
                        <p class="font-semibold text-gray-800">{{ ucfirst(str_replace('_', ' ', $job->status->value)) }}</p>
                        <p class="text-sm text-gray-500">{{ $job->status->display() }}</p>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800">₹{{ number_format($job->pay_amount) }}</p>
            </div>
        </div>

        <!-- Job Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Job Details</h3>
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-gray-500">Category</p>
                    <p class="font-medium text-gray-900">{{ $job->category->icon ?? '' }} {{ $job->category->name ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Job Date</p>
                    <p class="font-medium text-gray-900">{{ $job->job_date?->format('M d, Y') }} {{ $job->job_time ? '@ ' . $job->job_time : '' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Location</p>
                    <p class="font-medium text-gray-900">{{ $job->location_name ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Duration</p>
                    <p class="font-medium text-gray-900">{{ $job->duration_hours ? $job->duration_hours . ' hours' : '-' }}</p>
                </div>
            </div>

            @if($job->description)
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <p class="text-sm text-gray-500 mb-2">Description</p>
                    <p class="text-gray-700">{{ $job->description }}</p>
                </div>
            @endif

            @if($job->special_instructions)
                <div class="mt-4 p-4 bg-yellow-50 rounded-lg">
                    <p class="text-sm font-medium text-yellow-800 mb-1">Special Instructions</p>
                    <p class="text-yellow-700">{{ $job->special_instructions }}</p>
                </div>
            @endif
        </div>

        <!-- Timeline -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Timeline</h3>
            <div class="space-y-4">
                @foreach($timeline as $event)
                    <div class="flex items-start">
                        <span class="text-xl mr-3">{{ $event['icon'] }}</span>
                        <div class="flex-1">
                            <p class="font-medium text-gray-800">{{ $event['event'] }}</p>
                            <p class="text-sm text-gray-500">{{ $event['timestamp']->format('M d, Y H:i') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Applications -->
        @if($job->applications->count() > 0)
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Applications ({{ $job->applications->count() }})</h3>
                <div class="space-y-4">
                    @foreach($job->applications as $application)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center">
                                    <span class="text-indigo-600 font-medium">{{ substr($application->worker->name ?? '?', 0, 1) }}</span>
                                </div>
                                <div class="ml-3">
                                    <a href="{{ route('admin.jobs.workers.show', $application->worker) }}" class="text-sm font-medium text-gray-900 hover:text-indigo-600">
                                        {{ $application->worker->name ?? 'Unknown' }}
                                    </a>
                                    <div class="flex items-center text-xs text-gray-500">
                                        <span class="text-yellow-500">⭐</span>
                                        <span class="ml-1">{{ $application->worker->rating ?? 0 }}</span>
                                        <span class="mx-2">•</span>
                                        <span>{{ $application->worker->jobs_completed ?? 0 }} jobs</span>
                                    </div>
                                </div>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full {{ $application->status === 'accepted' ? 'bg-green-100 text-green-700' : ($application->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700') }}">
                                {{ ucfirst($application->status) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <!-- Right Column: Poster & Worker Info -->
    <div class="space-y-6">
        <!-- Poster Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Posted By</h3>
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <span class="text-blue-600 font-medium text-lg">{{ substr($job->poster->name ?? '?', 0, 1) }}</span>
                </div>
                <div class="ml-4">
                    <p class="font-medium text-gray-900">{{ $job->poster->name ?? 'Unknown' }}</p>
                    <p class="text-sm text-gray-500">{{ $job->poster->phone ?? '' }}</p>
                </div>
            </div>

            @if($posterJobs->count() > 0)
                <div class="pt-4 border-t border-gray-200">
                    <p class="text-sm text-gray-500 mb-3">Other Jobs by Poster</p>
                    <div class="space-y-2">
                        @foreach($posterJobs as $pJob)
                            <a href="{{ route('admin.jobs.posts.show', $pJob) }}" class="block text-sm text-indigo-600 hover:text-indigo-800">
                                {{ Str::limit($pJob->title, 25) }} - ₹{{ number_format($pJob->pay_amount) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Assigned Worker Info -->
        @if($job->assignedWorker)
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Assigned Worker</h3>
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                        <span class="text-indigo-600 font-medium text-lg">{{ substr($job->assignedWorker->name, 0, 1) }}</span>
                    </div>
                    <div class="ml-4">
                        <a href="{{ route('admin.jobs.workers.show', $job->assignedWorker) }}" class="font-medium text-gray-900 hover:text-indigo-600">
                            {{ $job->assignedWorker->name }}
                        </a>
                        <p class="text-sm text-gray-500">{{ $job->assignedWorker->user?->phone ?? '' }}</p>
                    </div>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Rating</span>
                        <span class="font-medium">⭐ {{ $job->assignedWorker->rating }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Jobs Completed</span>
                        <span class="font-medium">{{ $job->assignedWorker->jobs_completed }}</span>
                    </div>
                </div>
            </div>
        @endif

        <!-- Verification Info -->
        @if($job->verification)
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Verification</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Poster Verified</span>
                        <span class="{{ $job->verification->poster_verified ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $job->verification->poster_verified ? '✓ Yes' : 'No' }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Worker Verified</span>
                        <span class="{{ $job->verification->worker_verified ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $job->verification->worker_verified ? '✓ Yes' : 'No' }}
                        </span>
                    </div>
                    @if($job->verification->verification_code)
                        <div class="flex justify-between">
                            <span class="text-gray-500">Code</span>
                            <span class="font-mono font-medium">{{ $job->verification->verification_code }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <!-- Quick Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Info</h3>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Applications</span>
                    <span class="font-medium">{{ $job->applications_count }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Created</span>
                    <span class="font-medium">{{ $job->created_at->format('M d, Y H:i') }}</span>
                </div>
                @if($job->expires_at)
                    <div class="flex justify-between">
                        <span class="text-gray-500">Expires</span>
                        <span class="font-medium {{ $job->expires_at < now() ? 'text-red-600' : '' }}">
                            {{ $job->expires_at->format('M d, Y H:i') }}
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
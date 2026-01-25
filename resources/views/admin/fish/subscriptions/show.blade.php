@extends('admin.layouts.app')

@section('title', 'Subscription Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.fish.subscriptions.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Subscriptions</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Subscription Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto">
                    <span class="text-2xl">🔔</span>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $subscription->user->name ?? 'Unknown' }}</h2>
                <p class="text-gray-500">{{ $subscription->user->phone ?? 'N/A' }}</p>

                <div class="mt-4 flex justify-center gap-2">
                    @if($subscription->is_active && !$subscription->is_paused)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Active</span>
                    @elseif($subscription->is_paused)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-yellow-100 text-yellow-700">Paused</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Frequency</dt>
                        <dd class="font-medium text-gray-800">{{ ucfirst(str_replace('_', ' ', $subscription->frequency)) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Radius</dt>
                        <dd class="font-medium text-gray-800">{{ $subscription->radius_km }} km</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Fish Types</dt>
                        <dd class="font-medium text-gray-800">
                            @if($subscription->all_fish_types)
                                All types
                            @else
                                {{ $subscription->fishTypes->count() }} selected
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="font-medium text-gray-800">{{ $subscription->created_at->format('M j, Y') }}</dd>
                    </div>
                    @if($subscription->latitude && $subscription->longitude)
                    <div>
                        <dt class="text-gray-500">Location</dt>
                        <dd class="font-medium">
                            <a href="https://maps.google.com/?q={{ $subscription->latitude }},{{ $subscription->longitude }}"
                               target="_blank" class="text-blue-600 hover:text-blue-700">
                                View on Map ↗
                            </a>
                        </dd>
                    </div>
                    @endif
                </dl>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                <form method="POST" action="{{ route('admin.fish.subscriptions.' . ($subscription->is_active ? 'deactivate' : 'activate'), $subscription) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $subscription->is_active ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        {{ $subscription->is_active ? 'Deactivate Subscription' : 'Activate Subscription' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.fish.subscriptions.destroy', $subscription) }}" onsubmit="return confirm('Are you sure you want to delete this subscription?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="w-full py-2 px-4 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200">
                        Delete Subscription
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Alerts Received</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['alerts_received'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Delivered</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['alerts_delivered'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Clicked</dt>
                    <dd class="font-medium text-green-600">{{ $stats['alerts_clicked'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Click Rate</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['click_rate'] ?? 0 }}%</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Fish Types -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Selected Fish Types</h3>
            @if($subscription->all_fish_types)
                <p class="text-green-600">Subscribed to all fish types</p>
            @elseif($subscription->fishTypes->isEmpty())
                <p class="text-gray-500">No fish types selected</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach($subscription->fishTypes as $fishType)
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm">
                            {{ $fishType->name_en }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Recent Alerts -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Recent Alerts</h3>

            @if($subscription->alerts->isEmpty())
                <p class="text-gray-500 text-sm">No alerts sent to this subscription</p>
            @else
                <div class="space-y-4">
                    @foreach($subscription->alerts->take(10) as $alert)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <span class="text-2xl mr-3">🐟</span>
                                <div>
                                    <p class="font-medium text-gray-800">{{ $alert->catch->fishType->name_en ?? 'Unknown' }}</p>
                                    <p class="text-sm text-gray-500">{{ $alert->catch->seller->business_name ?? 'Unknown Seller' }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                @if($alert->clicked_at)
                                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Clicked</span>
                                @elseif($alert->delivered_at)
                                    <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700">Delivered</span>
                                @elseif($alert->sent_at)
                                    <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Sent</span>
                                @elseif($alert->failed_at)
                                    <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Failed</span>
                                @else
                                    <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Pending</span>
                                @endif
                                <p class="text-xs text-gray-400 mt-1">{{ $alert->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

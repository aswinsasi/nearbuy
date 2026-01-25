@extends('admin.layouts.app')

@section('title', 'Fish Subscriptions')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Phone or name..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Frequency</label>
            <select name="frequency" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="immediate" {{ request('frequency') === 'immediate' ? 'selected' : '' }}>Immediate</option>
                <option value="morning_only" {{ request('frequency') === 'morning_only' ? 'selected' : '' }}>Morning Only</option>
                <option value="twice_daily" {{ request('frequency') === 'twice_daily' ? 'selected' : '' }}>Twice Daily</option>
                <option value="weekly_digest" {{ request('frequency') === 'weekly_digest' ? 'selected' : '' }}>Weekly</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="active" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="true" {{ request('active') === 'true' ? 'selected' : '' }}>Active</option>
                <option value="false" {{ request('active') === 'false' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Paused</label>
            <select name="paused" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="true" {{ request('paused') === 'true' ? 'selected' : '' }}>Paused</option>
                <option value="false" {{ request('paused') === 'false' ? 'selected' : '' }}>Not Paused</option>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>
    </form>
</div>

<!-- Subscriptions Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subscriber</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frequency</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Radius</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fish Types</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($subscriptions as $subscription)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center">
                                <span class="text-purple-600 font-medium">{{ substr($subscription->user->name ?? 'U', 0, 1) }}</span>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">{{ $subscription->user->name ?? 'Unknown' }}</div>
                                <div class="text-sm text-gray-500">{{ $subscription->user->phone ?? '' }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $freqColors = [
                                'immediate' => 'bg-blue-100 text-blue-700',
                                'morning_only' => 'bg-yellow-100 text-yellow-700',
                                'twice_daily' => 'bg-green-100 text-green-700',
                                'weekly_digest' => 'bg-purple-100 text-purple-700',
                            ];
                        @endphp
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $freqColors[$subscription->frequency] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ ucfirst(str_replace('_', ' ', $subscription->frequency)) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $subscription->radius_km }} km
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        @if($subscription->all_fish_types)
                            <span class="text-green-600">All types</span>
                        @else
                            {{ $subscription->fishTypes->count() }} types
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex flex-col gap-1">
                            @if($subscription->is_active && !$subscription->is_paused)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">Active</span>
                            @elseif($subscription->is_paused)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700">Paused</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $subscription->created_at->format('M j, Y') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.fish.subscriptions.show', $subscription) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        <form method="POST" action="{{ route('admin.fish.subscriptions.' . ($subscription->is_active ? 'deactivate' : 'activate'), $subscription) }}" class="inline">
                            @csrf
                            <button type="submit" class="{{ $subscription->is_active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $subscription->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                        No subscriptions found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($subscriptions->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $subscriptions->links() }}
        </div>
    @endif
</div>
@endsection

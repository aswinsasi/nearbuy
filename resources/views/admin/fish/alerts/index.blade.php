@extends('admin.layouts.app')

@section('title', 'Fish Alerts')

@section('content')
<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow-sm p-4">
        <p class="text-sm text-gray-500">Total</p>
        <p class="text-2xl font-semibold text-gray-800">{{ number_format($alertStats['total'] ?? 0) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <p class="text-sm text-gray-500">Sent</p>
        <p class="text-2xl font-semibold text-green-600">{{ number_format($alertStats['sent'] ?? 0) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <p class="text-sm text-gray-500">Delivered</p>
        <p class="text-2xl font-semibold text-blue-600">{{ number_format($alertStats['delivered'] ?? 0) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <p class="text-sm text-gray-500">Clicked</p>
        <p class="text-2xl font-semibold text-purple-600">{{ number_format($alertStats['clicked'] ?? 0) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <p class="text-sm text-gray-500">Failed</p>
        <p class="text-2xl font-semibold text-red-600">{{ number_format($alertStats['failed'] ?? 0) }}</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                <option value="delivered" {{ request('status') === 'delivered' ? 'selected' : '' }}>Delivered</option>
                <option value="clicked" {{ request('status') === 'clicked' ? 'selected' : '' }}>Clicked</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
            <input type="date" name="from_date" value="{{ request('from_date') }}"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
            <input type="date" name="to_date" value="{{ request('to_date') }}"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>

        <div class="flex items-end gap-2">
            <form method="POST" action="{{ route('admin.fish.alerts.process-pending') }}" class="flex-1">
                @csrf
                <button type="submit" class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700">
                    Process Pending
                </button>
            </form>
        </div>
    </form>
</div>

<!-- Alerts Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alert</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subscriber</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fish/Seller</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sent</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($alerts as $alert)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">#{{ $alert->id }}</div>
                        <div class="text-xs text-gray-500">{{ $alert->created_at->format('M j, H:i') }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $alert->subscription->user->name ?? 'Unknown' }}</div>
                        <div class="text-sm text-gray-500">{{ $alert->subscription->user->phone ?? '' }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $alert->catch->fishType->name_en ?? 'Unknown' }}</div>
                        <div class="text-sm text-gray-500">{{ $alert->catch->seller->business_name ?? '' }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($alert->clicked_at)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">Clicked</span>
                            @if($alert->click_action)
                                <span class="block text-xs text-gray-500 mt-1">{{ $alert->click_action }}</span>
                            @endif
                        @elseif($alert->delivered_at)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700">Delivered</span>
                        @elseif($alert->sent_at)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700">Sent</span>
                        @elseif($alert->failed_at)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Failed</span>
                            @if($alert->failure_reason)
                                <span class="block text-xs text-red-500 mt-1 max-w-[150px] truncate" title="{{ $alert->failure_reason }}">
                                    {{ $alert->failure_reason }}
                                </span>
                            @endif
                        @else
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">Pending</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $alert->sent_at ? $alert->sent_at->format('M j, H:i') : '-' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.fish.alerts.show', $alert) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        @if($alert->failed_at)
                            <form method="POST" action="{{ route('admin.fish.alerts.retry', $alert) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-green-600 hover:text-green-700">Retry</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No alerts found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($alerts->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $alerts->links() }}
        </div>
    @endif
</div>
@endsection

@extends('admin.layouts.app')

@section('title', 'Fish Module Dashboard')

@section('content')
<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Fish Sellers -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-cyan-100 rounded-lg">
                <span class="text-2xl">🎣</span>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Fish Sellers</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['sellers']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-green-600">{{ $stats['sellers']['active'] }} active</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-blue-600">{{ $stats['sellers']['verified'] }} verified</span>
        </div>
    </div>

    <!-- Today's Catches -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-blue-100 rounded-lg">
                <span class="text-2xl">🐟</span>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Today's Catches</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['catches']['today']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-green-600">{{ $stats['catches']['available'] }} available</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-yellow-600">{{ $stats['catches']['low_stock'] }} low stock</span>
        </div>
    </div>

    <!-- Active Subscriptions -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-purple-100 rounded-lg">
                <span class="text-2xl">🔔</span>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Subscriptions</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['subscriptions']['total']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-green-600">{{ $stats['subscriptions']['active'] }} active</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-gray-500">{{ $stats['subscriptions']['paused'] }} paused</span>
        </div>
    </div>

    <!-- Alerts Today -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex items-center">
            <div class="p-3 bg-green-100 rounded-lg">
                <span class="text-2xl">📨</span>
            </div>
            <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Alerts Today</p>
                <p class="text-2xl font-semibold text-gray-800">{{ number_format($stats['alerts']['sent_today']) }}</p>
            </div>
        </div>
        <div class="mt-4 flex text-sm">
            <span class="text-yellow-600">{{ $stats['alerts']['pending'] }} pending</span>
            <span class="mx-2 text-gray-300">•</span>
            <span class="text-red-600">{{ $stats['alerts']['failed_today'] }} failed</span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-8">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
    <div class="flex flex-wrap gap-4">
        <form method="POST" action="{{ route('admin.fish.catches.expire-stale') }}" class="inline">
            @csrf
            <button type="submit" class="px-4 py-2 bg-yellow-100 text-yellow-700 rounded-lg hover:bg-yellow-200">
                🕐 Expire Stale Catches
            </button>
        </form>
        <form method="POST" action="{{ route('admin.fish.alerts.process-pending') }}" class="inline">
            @csrf
            <button type="submit" class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200">
                📤 Process Pending Alerts
            </button>
        </form>
        <a href="{{ route('admin.fish.types.index') }}" class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200">
            ➕ Manage Fish Types
        </a>
    </div>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Catches by Status -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Catches by Status</h3>
        <canvas id="catchStatusChart" height="200"></canvas>
    </div>

    <!-- Subscriptions by Frequency -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Subscriptions by Frequency</h3>
        <canvas id="subscriptionFreqChart" height="200"></canvas>
    </div>
</div>

<!-- Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Catches -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Catches</h3>
        <div class="space-y-4">
            @forelse($recentCatches as $catch)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">🐟</span>
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ $catch->fishType->name_en ?? 'Unknown' }}</p>
                            <p class="text-xs text-gray-500">{{ $catch->seller->business_name ?? 'Unknown Seller' }}</p>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full {{ $catch->status === 'available' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($catch->status) }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent catches</p>
            @endforelse
        </div>
        <a href="{{ route('admin.fish.catches.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all catches →</a>
    </div>

    <!-- Recent Sellers -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Sellers</h3>
        <div class="space-y-4">
            @forelse($recentSellers as $seller)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-cyan-100 rounded-full flex items-center justify-center">
                            <span class="text-cyan-600 font-medium">{{ substr($seller->business_name, 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-800">{{ $seller->business_name }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $seller->seller_type)) }}</p>
                        </div>
                    </div>
                    @if($seller->verified_at)
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Verified</span>
                    @else
                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Unverified</span>
                    @endif
                </div>
            @empty
                <p class="text-gray-500 text-sm">No recent sellers</p>
            @endforelse
        </div>
        <a href="{{ route('admin.fish.sellers.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all sellers →</a>
    </div>

    <!-- Top Fish Types -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Popular Fish Types</h3>
        <div class="space-y-4">
            @forelse($topFishTypes as $fishType)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">{{ $fishType->is_popular ? '⭐' : '🐟' }}</span>
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ $fishType->name_en }}</p>
                            <p class="text-xs text-gray-500">{{ $fishType->name_ml }}</p>
                        </div>
                    </div>
                    <span class="text-sm text-gray-600">{{ $fishType->catches_count }} catches</span>
                </div>
            @empty
                <p class="text-gray-500 text-sm">No fish types</p>
            @endforelse
        </div>
        <a href="{{ route('admin.fish.types.index') }}" class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all types →</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Catch Status Chart
new Chart(document.getElementById('catchStatusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Available', 'Low Stock', 'Sold Out', 'Expired'],
        datasets: [{
            data: [
                {{ $stats['catches']['available'] }},
                {{ $stats['catches']['low_stock'] }},
                {{ $stats['catches']['sold_out'] ?? 0 }},
                {{ $stats['catches']['expired'] ?? 0 }}
            ],
            backgroundColor: ['#10B981', '#F59E0B', '#6B7280', '#EF4444']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'right' } }
    }
});

// Subscription Frequency Chart
// Subscription Frequency Chart
new Chart(document.getElementById('subscriptionFreqChart'), {
    type: 'pie',
    data: {
        labels: ['Immediate', 'Morning Only', 'Twice Daily', 'Weekly'],
        datasets: [{
            data: [
                {{ $stats['subscriptions']['by_frequency'][0] ?? 0 }},
                {{ $stats['subscriptions']['by_frequency'][1] ?? 0 }},
                {{ $stats['subscriptions']['by_frequency'][2] ?? 0 }},
                {{ $stats['subscriptions']['by_frequency'][3] ?? 0 }}
            ],
            backgroundColor: ['#3B82F6', '#F59E0B', '#10B981', '#8B5CF6']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'right' } }
    }
});
</script>
@endpush

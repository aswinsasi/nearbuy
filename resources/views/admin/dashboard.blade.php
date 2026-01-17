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

<!-- Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
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
</script>
@endpush
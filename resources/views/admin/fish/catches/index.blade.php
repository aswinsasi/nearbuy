@extends('admin.layouts.app')

@section('title', 'Fish Catches')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Catch # or fish..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Available</option>
                <option value="low_stock" {{ request('status') === 'low_stock' ? 'selected' : '' }}>Low Stock</option>
                <option value="sold_out" {{ request('status') === 'sold_out' ? 'selected' : '' }}>Sold Out</option>
                <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Fish Type</label>
            <select name="fish_type_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Types</option>
                @foreach($fishTypes as $type)
                    <option value="{{ $type->id }}" {{ request('fish_type_id') == $type->id ? 'selected' : '' }}>
                        {{ $type->name_en }}
                    </option>
                @endforeach
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
    </form>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-xl shadow-sm p-4 mb-6">
    <div class="flex flex-wrap gap-4">
        <form method="POST" action="{{ route('admin.fish.catches.expire-stale') }}" class="inline">
            @csrf
            <button type="submit" class="px-4 py-2 bg-yellow-100 text-yellow-700 rounded-lg hover:bg-yellow-200">
                🕐 Expire Stale Catches
            </button>
        </form>
    </div>
</div>

<!-- Catches Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catch</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Seller</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Views</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($catches as $catch)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="text-2xl mr-3">🐟</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">{{ $catch->fishType->name_en ?? 'Unknown' }}</div>
                                <div class="text-sm text-gray-500">{{ $catch->catch_number }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $catch->seller->business_name ?? 'Unknown' }}</div>
                        <div class="text-sm text-gray-500">{{ $catch->seller->user->phone ?? '' }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ₹{{ number_format($catch->price_per_kg) }}/kg
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $catch->views ?? 0 }} views
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $statusColors = [
                                'available' => 'bg-green-100 text-green-700',
                                'low_stock' => 'bg-yellow-100 text-yellow-700',
                                'sold_out' => 'bg-gray-100 text-gray-700',
                                'expired' => 'bg-red-100 text-red-700',
                            ];
                        @endphp
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $statusColors[$catch->status->value] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ ucfirst(str_replace('_', ' ', $catch->status->value)) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $catch->created_at->format('M j, H:i') }}
                        @if($catch->expires_at)
                            <br>
                            <span class="text-xs {{ $catch->expires_at->isPast() ? 'text-red-500' : 'text-gray-400' }}">
                                Expires: {{ $catch->expires_at->format('H:i') }}
                            </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.fish.catches.show', $catch) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        @if($catch->status->value === 'available')
                            <form method="POST" action="{{ route('admin.fish.catches.update-status', $catch) }}" class="inline">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="sold_out">
                                <button type="submit" class="text-gray-600 hover:text-gray-700">Mark Sold</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                        No catches found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($catches->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $catches->links() }}
        </div>
    @endif
</div>
@endsection
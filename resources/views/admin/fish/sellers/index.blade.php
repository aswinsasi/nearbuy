@extends('admin.layouts.app')

@section('title', 'Fish Sellers')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Name or phone..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Seller Type</label>
            <select name="seller_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Types</option>
                <option value="fisherman" {{ request('seller_type') === 'fisherman' ? 'selected' : '' }}>Fisherman</option>
                <option value="harbour_vendor" {{ request('seller_type') === 'harbour_vendor' ? 'selected' : '' }}>Harbour Vendor</option>
                <option value="fish_shop" {{ request('seller_type') === 'fish_shop' ? 'selected' : '' }}>Fish Shop</option>
                <option value="wholesaler" {{ request('seller_type') === 'wholesaler' ? 'selected' : '' }}>Wholesaler</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Verification</label>
            <select name="verified" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="yes" {{ request('verified') === 'yes' ? 'selected' : '' }}>Verified</option>
                <option value="no" {{ request('verified') === 'no' ? 'selected' : '' }}>Unverified</option>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>
    </form>
</div>

<!-- Sellers Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Seller</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Active Catches</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($sellers as $seller)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-cyan-100 rounded-full flex items-center justify-center">
                                <span class="text-cyan-600 font-medium">{{ substr($seller->business_name, 0, 1) }}</span>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">{{ $seller->business_name }}</div>
                                <div class="text-sm text-gray-500">{{ $seller->user->name ?? 'N/A' }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $typeColors = [
                                'fisherman' => 'bg-blue-100 text-blue-700',
                                'harbour_vendor' => 'bg-purple-100 text-purple-700',
                                'fish_shop' => 'bg-green-100 text-green-700',
                                'wholesaler' => 'bg-orange-100 text-orange-700',
                            ];
                        @endphp
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $typeColors[$seller->seller_type] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ ucfirst(str_replace('_', ' ', $seller->seller_type)) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $seller->user->phone ?? 'N/A' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $seller->catches->count() }} catches
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex flex-col gap-1">
                            @if($seller->is_active)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">Active</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                            @endif
                            @if($seller->verified_at)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700">✓ Verified</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.fish.sellers.show', $seller) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        @if(!$seller->verified_at)
                            <form method="POST" action="{{ route('admin.fish.sellers.verify', $seller) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-green-600 hover:text-green-700 mr-3">Verify</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.fish.sellers.' . ($seller->is_active ? 'deactivate' : 'reactivate'), $seller) }}" class="inline">
                            @csrf
                            <button type="submit" class="{{ $seller->is_active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $seller->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No fish sellers found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($sellers->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $sellers->links() }}
        </div>
    @endif
</div>
@endsection

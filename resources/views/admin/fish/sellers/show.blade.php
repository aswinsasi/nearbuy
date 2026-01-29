@extends('admin.layouts.app')

@section('title', 'Fish Seller Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.fish.sellers.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Fish Sellers</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Seller Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-cyan-100 rounded-full flex items-center justify-center mx-auto">
                    <span class="text-2xl text-cyan-600 font-medium">{{ substr($seller->business_name, 0, 1) }}</span>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $seller->business_name }}</h2>
                <p class="text-gray-500">{{ $seller->user->phone ?? 'N/A' }}</p>

                <div class="mt-4 flex justify-center gap-2 flex-wrap">
                    @php
                        $typeColors = [
                            'fisherman' => 'bg-blue-100 text-blue-700',
                            'harbour_vendor' => 'bg-purple-100 text-purple-700',
                            'fish_shop' => 'bg-green-100 text-green-700',
                            'wholesaler' => 'bg-orange-100 text-orange-700',
                        ];
                        $sellerTypeValue = $seller->seller_type->value ?? $seller->seller_type;
                    @endphp
                    <span class="px-3 py-1 text-sm font-medium rounded-full {{ $typeColors[$sellerTypeValue] ?? 'bg-gray-100 text-gray-700' }}">
                        {{ ucfirst(str_replace('_', ' ', $sellerTypeValue)) }}
                    </span>
                    @if($seller->is_active)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Active</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                    @endif
                    @if($seller->verified_at)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-blue-100 text-blue-700">✓ Verified</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Owner Name</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $seller->user->name ?? 'N/A' }}</dd>
                    </div>
                    @if($seller->market_name)
                    <div>
                        <dt class="text-sm text-gray-500">Market/Location</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $seller->market_name }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-sm text-gray-500">Registered</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $seller->created_at->format('F j, Y') }}</dd>
                    </div>
                    @if($seller->verified_at)
                    <div>
                        <dt class="text-sm text-gray-500">Verified On</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $seller->verified_at->format('F j, Y') }}</dd>
                    </div>
                    @endif
                    @if($seller->latitude && $seller->longitude)
                    <div>
                        <dt class="text-sm text-gray-500">Location</dt>
                        <dd class="text-sm font-medium text-gray-800">
                            <a href="https://maps.google.com/?q={{ $seller->latitude }},{{ $seller->longitude }}"
                               target="_blank" class="text-blue-600 hover:text-blue-700">
                                View on Map ↗
                            </a>
                        </dd>
                    </div>
                    @endif
                </dl>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                @if(!$seller->verified_at)
                    <form method="POST" action="{{ route('admin.fish.sellers.verify', $seller) }}">
                        @csrf
                        <button type="submit" class="w-full py-2 px-4 rounded-lg bg-blue-100 text-blue-700 hover:bg-blue-200">
                            ✓ Verify Seller
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('admin.fish.sellers.' . ($seller->is_active ? 'deactivate' : 'reactivate'), $seller) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $seller->is_active ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        {{ $seller->is_active ? 'Deactivate Seller' : 'Activate Seller' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Today's Catches</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['today_catches'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Today's Views</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['today_views'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">This Week</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['week_catches'] ?? 0 }} catches</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Catches</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_catches'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Views</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_views'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Coming Responses</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['week_coming'] ?? 0 }}</dd>
                </div>
                @if(isset($stats['avg_rating']) && $stats['avg_rating'])
                <div class="flex justify-between">
                    <dt class="text-gray-500">Average Rating</dt>
                    <dd class="font-medium text-yellow-600">{{ number_format($stats['avg_rating'], 1) }} ⭐</dd>
                </div>
                @endif
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Active Catches -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Active Catches</h3>
                <a href="{{ route('admin.fish.catches.index', ['seller_id' => $seller->id]) }}"
                   class="text-blue-600 hover:text-blue-700 text-sm">View All →</a>
            </div>

            @if($seller->catches->isEmpty())
                <p class="text-gray-500 text-sm">No active catches</p>
            @else
                <div class="space-y-4">
                    @foreach($seller->catches->take(5) as $catch)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <span class="text-2xl mr-3">🐟</span>
                                <div>
                                    <p class="font-medium text-gray-800">{{ $catch->fishType->name_en ?? 'Unknown' }}</p>
                                    <p class="text-sm text-gray-500">
                                        {{ $catch->catch_number }} • ₹{{ number_format($catch->price_per_kg) }}/kg
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                @php
                                    $statusColors = [
                                        'available' => 'bg-green-100 text-green-700',
                                        'low_stock' => 'bg-yellow-100 text-yellow-700',
                                        'sold_out' => 'bg-gray-100 text-gray-700',
                                        'expired' => 'bg-red-100 text-red-700',
                                    ];
                                    $catchStatusValue = $catch->status->value ?? $catch->status;
                                @endphp
                                <span class="px-2 py-1 text-xs rounded-full {{ $statusColors[$catchStatusValue] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucfirst(str_replace('_', ' ', $catchStatusValue)) }}
                                </span>
                                <p class="text-xs text-gray-400 mt-1">{{ $catch->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Edit Seller -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Edit Seller Details</h3>
            <form method="POST" action="{{ route('admin.fish.sellers.update', $seller) }}">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                        <input type="text" name="business_name" value="{{ $seller->business_name }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Seller Type</label>
                        @php $sellerTypeVal = $seller->seller_type->value ?? $seller->seller_type; @endphp
                        <select name="seller_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                            <option value="fisherman" {{ $sellerTypeVal === 'fisherman' ? 'selected' : '' }}>Fisherman</option>
                            <option value="harbour_vendor" {{ $sellerTypeVal === 'harbour_vendor' ? 'selected' : '' }}>Harbour Vendor</option>
                            <option value="fish_shop" {{ $sellerTypeVal === 'fish_shop' ? 'selected' : '' }}>Fish Shop</option>
                            <option value="wholesaler" {{ $sellerTypeVal === 'wholesaler' ? 'selected' : '' }}>Wholesaler</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Market/Location Name</label>
                        <input type="text" name="market_name" value="{{ $seller->market_name }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
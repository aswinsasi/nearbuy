@extends('admin.layouts.app')

@section('title', 'Offer Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.offers.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Offers</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Offer Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <!-- Image -->
            <div class="h-48 bg-gray-100 relative">
                @if($offer->image_url)
                    <img src="{{ $offer->image_url }}" alt="{{ $offer->title }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-16 h-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                @endif

                <!-- Status Badge -->
                <div class="absolute top-3 right-3">
                    @if($offer->is_active && ($offer->valid_until === null || $offer->valid_until > now()))
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-500 text-white">Active</span>
                    @elseif($offer->valid_until && $offer->valid_until < now())
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-500 text-white">Expired</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-gray-500 text-white">Inactive</span>
                    @endif
                </div>
            </div>

            <div class="p-6">
                <h2 class="text-xl font-semibold text-gray-800">{{ $offer->title }}</h2>
                
                <p class="text-gray-600 mt-2">{{ $offer->description }}</p>

                <dl class="mt-6 space-y-4">
                    @if($offer->price)
                    <div>
                        <dt class="text-sm text-gray-500">Price</dt>
                        <dd class="text-lg font-semibold text-green-600">₹{{ number_format($offer->price) }}</dd>
                    </div>
                    @endif

                    @if($offer->discount_percent)
                    <div>
                        <dt class="text-sm text-gray-500">Discount</dt>
                        <dd class="text-lg font-semibold text-orange-600">{{ $offer->discount_percent }}% OFF</dd>
                    </div>
                    @endif

                    <div>
                        <dt class="text-sm text-gray-500">Valid From</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $offer->valid_from?->format('M j, Y') ?? $offer->created_at->format('M j, Y') }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm text-gray-500">Valid Until</dt>
                        <dd class="text-sm font-medium text-gray-800">
                            @if($offer->valid_until)
                                {{ $offer->valid_until->format('M j, Y') }}
                                @if($offer->valid_until < now())
                                    <span class="text-red-500 text-xs">(Expired)</span>
                                @else
                                    <span class="text-green-500 text-xs">({{ $offer->valid_until->diffForHumans() }})</span>
                                @endif
                            @else
                                <span class="text-gray-500">No expiry date</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-gray-500">Created</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $offer->created_at->format('M j, Y H:i') }}</dd>
                    </div>
                </dl>

                <!-- Actions -->
                <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                    <form method="POST" action="{{ route('admin.offers.toggle-active', $offer) }}">
                        @csrf
                        <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $offer->is_active ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                            {{ $offer->is_active ? '⏸️ Deactivate Offer' : '▶️ Activate Offer' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.offers.destroy', $offer) }}" 
                          onsubmit="return confirm('Are you sure you want to delete this offer?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full py-2 px-4 rounded-lg bg-red-100 text-red-700 hover:bg-red-200">
                            🗑️ Delete Offer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Shop & Metrics -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Shop Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Shop Information</h3>

            <div class="flex items-start">
                <div class="w-16 h-16 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <span class="text-2xl text-emerald-600 font-medium">{{ substr($offer->shop->shop_name ?? 'N', 0, 1) }}</span>
                </div>
                <div class="ml-4 flex-1">
                    <h4 class="font-medium text-gray-800">{{ $offer->shop->shop_name ?? 'Unknown Shop' }}</h4>
                    <p class="text-sm text-gray-500">{{ ucfirst($offer->shop->category?->value ?? 'N/A') }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ $offer->shop->address ?? '' }}</p>
                    
                    <div class="mt-3 flex gap-2">
                        @if($offer->shop?->verified)
                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">✓ Verified</span>
                        @endif
                        @if($offer->shop?->is_active)
                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700">Active</span>
                        @endif
                    </div>
                </div>
                @if($offer->shop)
                    <a href="{{ route('admin.shops.show', $offer->shop) }}" 
                       class="px-4 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        View Shop
                    </a>
                @endif
            </div>

            <!-- Owner -->
            @if($offer->shop?->owner)
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h4 class="text-sm font-medium text-gray-700 mb-3">Shop Owner</h4>
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                            <span class="text-gray-600 font-medium">{{ substr($offer->shop->owner->name, 0, 1) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-800">{{ $offer->shop->owner->name }}</p>
                            <p class="text-xs text-gray-500">{{ $offer->shop->owner->phone }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.users.show', $offer->shop->owner) }}" 
                       class="text-sm text-blue-600 hover:text-blue-700">
                        View Profile →
                    </a>
                </div>
            </div>
            @endif
        </div>

        <!-- Metrics -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Offer Metrics</h3>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600">{{ $offer->view_count ?? 0 }}</p>
                    <p class="text-sm text-blue-700">Views</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-green-600">{{ $offer->click_count ?? 0 }}</p>
                    <p class="text-sm text-green-700">Clicks</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600">{{ $offer->share_count ?? 0 }}</p>
                    <p class="text-sm text-purple-700">Shares</p>
                </div>
                <div class="bg-orange-50 rounded-lg p-4 text-center">
                    @php
                        $ctr = ($offer->view_count ?? 0) > 0 
                            ? round((($offer->click_count ?? 0) / $offer->view_count) * 100, 1) 
                            : 0;
                    @endphp
                    <p class="text-2xl font-bold text-orange-600">{{ $ctr }}%</p>
                    <p class="text-sm text-orange-700">CTR</p>
                </div>
            </div>

            <!-- Performance over time could be added here with a chart -->
        </div>

        <!-- Activity Log -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Activity</h3>

            <div class="space-y-4">
                <div class="flex items-center text-sm">
                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3">
                        <span class="text-green-600">+</span>
                    </div>
                    <div>
                        <p class="text-gray-800">Offer created</p>
                        <p class="text-gray-500 text-xs">{{ $offer->created_at->format('M j, Y H:i') }}</p>
                    </div>
                </div>

                @if($offer->updated_at != $offer->created_at)
                <div class="flex items-center text-sm">
                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                        <span class="text-blue-600">✎</span>
                    </div>
                    <div>
                        <p class="text-gray-800">Last updated</p>
                        <p class="text-gray-500 text-xs">{{ $offer->updated_at->format('M j, Y H:i') }}</p>
                    </div>
                </div>
                @endif

                @if(!$offer->is_active)
                <div class="flex items-center text-sm">
                    <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                        <span class="text-yellow-600">⏸</span>
                    </div>
                    <div>
                        <p class="text-gray-800">Offer deactivated</p>
                        <p class="text-gray-500 text-xs">By admin or owner</p>
                    </div>
                </div>
                @endif

                @if($offer->valid_until && $offer->valid_until < now())
                <div class="flex items-center text-sm">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                        <span class="text-red-600">⏰</span>
                    </div>
                    <div>
                        <p class="text-gray-800">Offer expired</p>
                        <p class="text-gray-500 text-xs">{{ $offer->valid_until->format('M j, Y H:i') }}</p>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
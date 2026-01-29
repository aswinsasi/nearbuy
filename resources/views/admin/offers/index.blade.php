@extends('admin.layouts.app')

@section('title', 'Offers')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Title, description..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Categories</option>
                <option value="grocery" {{ request('category') === 'grocery' ? 'selected' : '' }}>Grocery</option>
                <option value="electronics" {{ request('category') === 'electronics' ? 'selected' : '' }}>Electronics</option>
                <option value="clothes" {{ request('category') === 'clothes' ? 'selected' : '' }}>Clothes</option>
                <option value="medical" {{ request('category') === 'medical' ? 'selected' : '' }}>Medical</option>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>
    </form>
</div>

<!-- Offers Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    @forelse($offers as $offer)
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <!-- Image -->
            <div class="h-48 bg-gray-100 relative">
                @if($offer->image_url)
                    <img src="{{ $offer->image_url }}" alt="{{ $offer->title }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                @endif

                <!-- Status Badge -->
                <div class="absolute top-3 right-3">
                    @if($offer->is_active && ($offer->valid_until === null || $offer->valid_until > now()))
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-500 text-white">Active</span>
                    @elseif($offer->valid_until && $offer->valid_until < now())
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-500 text-white">Expired</span>
                    @else
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-500 text-white">Inactive</span>
                    @endif
                </div>
            </div>

            <!-- Content -->
            <div class="p-4">
                <h3 class="font-semibold text-gray-800">{{ $offer->title }}</h3>
                <p class="text-sm text-gray-500 mt-1">{{ Str::limit($offer->description, 60) }}</p>

                <div class="mt-3 flex items-center text-sm text-gray-500">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <a href="{{ route('admin.shops.show', $offer->shop) }}" class="text-blue-600 hover:text-blue-700">
                        {{ $offer->shop->shop_name ?? 'Unknown' }}
                    </a>
                </div>

                <div class="mt-2 flex items-center text-sm text-gray-500">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    @if($offer->valid_until)
                        Valid until {{ $offer->valid_until->format('M j, Y') }}
                    @else
                        No expiry date
                    @endif
                </div>

                <!-- Actions -->
                <div class="mt-4 flex justify-between items-center pt-4 border-t border-gray-100">
                    <a href="{{ route('admin.offers.show', $offer) }}" class="text-blue-600 hover:text-blue-700 text-sm">
                        View Details
                    </a>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('admin.offers.toggle-active', $offer) }}">
                            @csrf
                            <button type="submit" class="text-sm {{ $offer->is_active ? 'text-yellow-600 hover:text-yellow-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $offer->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.offers.destroy', $offer) }}" onsubmit="return confirm('Delete this offer?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:text-red-700">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-span-3 bg-white rounded-xl shadow-sm p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
            </svg>
            <p class="text-gray-500">No offers found</p>
        </div>
    @endforelse
</div>

<!-- Pagination -->
@if($offers->hasPages())
    <div class="mt-6">
        {{ $offers->links() }}
    </div>
@endif
@endsection
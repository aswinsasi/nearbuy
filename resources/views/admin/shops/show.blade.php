@extends('admin.layouts.app')

@section('title', 'Shop Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.shops.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Shops</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Shop Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-emerald-100 rounded-xl flex items-center justify-center mx-auto">
                    <span class="text-2xl text-emerald-600 font-medium">{{ substr($shop->shop_name, 0, 1) }}</span>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $shop->shop_name }}</h2>
                <p class="text-gray-500">{{ ucfirst($shop->category) }}</p>

                <div class="mt-4 flex justify-center gap-2">
                    @if($shop->verified)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Verified</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-yellow-100 text-yellow-700">Unverified</span>
                    @endif
                    @if($shop->is_active)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-blue-100 text-blue-700">Active</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Owner</dt>
                        <dd class="text-sm font-medium text-gray-800">
                            <a href="{{ route('admin.users.show', $shop->owner) }}" class="text-blue-600 hover:text-blue-700">
                                {{ $shop->owner->name ?? 'N/A' }}
                            </a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Phone</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $shop->owner->phone ?? 'N/A' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Address</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $shop->address }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Location</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $shop->latitude }}, {{ $shop->longitude }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Notification Frequency</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ ucfirst(str_replace('_', ' ', $shop->notification_frequency ?? 'immediate')) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Registered</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $shop->created_at->format('F j, Y') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                <form method="POST" action="{{ route('admin.shops.toggle-verification', $shop) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $shop->verified ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        {{ $shop->verified ? 'Remove Verification' : 'Verify Shop' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.shops.toggle-active', $shop) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $shop->is_active ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-blue-100 text-blue-700 hover:bg-blue-200' }}">
                        {{ $shop->is_active ? 'Deactivate Shop' : 'Activate Shop' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Offers</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_offers'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Active Offers</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['active_offers'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Responses</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_responses'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Available Responses</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['available_responses'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Avg. Response Time</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['avg_response_time'] ?? 'N/A' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Offers -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Active Offers</h3>

            @if($shop->offers->isEmpty())
                <p class="text-gray-500 text-sm">No offers found</p>
            @else
                <div class="space-y-4">
                    @foreach($shop->offers as $offer)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                @if($offer->image_url)
                                    <img src="{{ $offer->image_url }}" alt="" class="w-16 h-16 rounded-lg object-cover">
                                @else
                                    <div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif
                                <div class="ml-4">
                                    <p class="font-medium text-gray-800">{{ $offer->title }}</p>
                                    <p class="text-sm text-gray-500">{{ Str::limit($offer->description, 50) }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                @if($offer->is_active && $offer->valid_until > now())
                                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Active</span>
                                @elseif($offer->valid_until < now())
                                    <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Expired</span>
                                @else
                                    <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Inactive</span>
                                @endif
                                <p class="text-xs text-gray-400 mt-1">Until {{ $offer->valid_until->format('M j, Y') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Response History -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Recent Responses</h3>

            @if($responses->isEmpty())
                <p class="text-gray-500 text-sm">No responses found</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Request</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Price</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($responses as $response)
                                <tr>
                                    <td class="px-4 py-3">
                                        <p class="text-sm text-gray-800">{{ Str::limit($response->request->description ?? 'N/A', 40) }}</p>
                                        <p class="text-xs text-gray-500">{{ $response->request->request_number ?? '' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-800">
                                        @if($response->is_available)
                                            ₹{{ number_format($response->price ?? 0) }}
                                        @else
                                            <span class="text-gray-500">Not Available</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($response->is_available)
                                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Available</span>
                                        @else
                                            <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Unavailable</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        {{ $response->created_at->format('M j, Y H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
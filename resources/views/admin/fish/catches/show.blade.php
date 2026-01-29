@extends('admin.layouts.app')

@section('title', 'Catch Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.fish.catches.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Catches</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Catch Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <span class="text-6xl">🐟</span>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $catch->fishType->name_en ?? 'Unknown' }}</h2>
                <p class="text-gray-500">{{ $catch->fishType->name_ml ?? '' }}</p>
                <p class="text-sm text-gray-400 mt-1">{{ $catch->catch_number }}</p>

                <div class="mt-4">
                    @php
                        $statusColors = [
                            'available' => 'bg-green-100 text-green-700',
                            'low_stock' => 'bg-yellow-100 text-yellow-700',
                            'sold_out' => 'bg-gray-100 text-gray-700',
                            'expired' => 'bg-red-100 text-red-700',
                        ];
                    @endphp
                    <span class="px-3 py-1 text-sm font-medium rounded-full {{ $statusColors[$catch->status->value] ?? 'bg-gray-100 text-gray-700' }}">
                        {{ ucfirst(str_replace('_', ' ', $catch->status->value)) }}
                    </span>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Price</dt>
                        <dd class="font-medium text-gray-800">₹{{ number_format($catch->price_per_kg) }}/kg</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Quantity</dt>
                        <dd class="font-medium text-gray-800">
                            {{ $catch->quantity_kg_min }}
                            @if($catch->quantity_kg_max && $catch->quantity_kg_max != $catch->quantity_kg_min)
                                - {{ $catch->quantity_kg_max }}
                            @endif
                            kg
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Posted</dt>
                        <dd class="font-medium text-gray-800">{{ $catch->created_at->format('M j, Y H:i') }}</dd>
                    </div>
                    @if($catch->expires_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Expires</dt>
                        <dd class="font-medium {{ $catch->expires_at->isPast() ? 'text-red-600' : 'text-gray-800' }}">
                            {{ $catch->expires_at->format('M j, Y H:i') }}
                        </dd>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Views</dt>
                        <dd class="font-medium text-gray-800">{{ $catch->views ?? 0 }}</dd>
                    </div>
                </dl>
            </div>

            @if($catch->catch_latitude && $catch->catch_longitude)
            <div class="mt-6 pt-6 border-t border-gray-200">
                <a href="https://maps.google.com/?q={{ $catch->catch_latitude }},{{ $catch->catch_longitude }}"
                   target="_blank" class="text-blue-600 hover:text-blue-700 text-sm">
                    📍 View Location on Map ↗
                </a>
            </div>
            @endif

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                <form method="POST" action="{{ route('admin.fish.catches.update-status', $catch) }}">
                    @csrf
                    @method('PUT')
                    <div class="flex gap-2 mb-2">
                        <select name="status" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="available" {{ $catch->status->value === 'available' ? 'selected' : '' }}>Available</option>
                            <option value="low_stock" {{ $catch->status->value === 'low_stock' ? 'selected' : '' }}>Low Stock</option>
                            <option value="sold_out" {{ $catch->status->value === 'sold_out' ? 'selected' : '' }}>Sold Out</option>
                            <option value="expired" {{ $catch->status->value === 'expired' ? 'selected' : '' }}>Expired</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                            Update
                        </button>
                    </div>
                </form>

                @if($catch->status->value === 'available' || $catch->status->value === 'low_stock')
                <form method="POST" action="{{ route('admin.fish.catches.extend-expiry', $catch) }}">
                    @csrf
                    <div class="flex gap-2">
                        <select name="hours" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="1">+1 hour</option>
                            <option value="2">+2 hours</option>
                            <option value="4">+4 hours</option>
                            <option value="8">+8 hours</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                            Extend
                        </button>
                    </div>
                </form>
                @endif
            </div>
        </div>

        <!-- Seller Info -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Seller</h3>
            <div class="flex items-center">
                <div class="w-12 h-12 bg-cyan-100 rounded-full flex items-center justify-center">
                    <span class="text-cyan-600 font-medium">{{ substr($catch->seller->business_name ?? 'U', 0, 1) }}</span>
                </div>
                <div class="ml-4">
                    <p class="font-medium text-gray-800">{{ $catch->seller->business_name ?? 'Unknown' }}</p>
                    <p class="text-sm text-gray-500">{{ $catch->seller->user->phone ?? '' }}</p>
                </div>
            </div>
            <a href="{{ route('admin.fish.sellers.show', $catch->seller) }}"
               class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View Seller →</a>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Views</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['views'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Coming Responses</dt>
                    <dd class="font-medium text-green-600">{{ $stats['coming_count'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Message Responses</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['message_count'] ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Alerts Sent</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['alerts_sent'] ?? 0 }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Photo -->
        @if($catch->photo_url)
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Photo</h3>
            <img src="{{ $catch->photo_url }}" alt="Catch photo" class="rounded-lg max-h-64 object-cover">
        </div>
        @endif

        <!-- Alerts Sent -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Alerts Sent</h3>
                <span class="text-sm text-gray-500">{{ $catch->alerts->count() }} total</span>
            </div>

            @if($catch->alerts->isEmpty())
                <p class="text-gray-500 text-sm">No alerts sent for this catch</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Subscriber</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Sent</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($catch->alerts->take(20) as $alert)
                                <tr>
                                    <td class="px-4 py-2 text-sm">
                                        {{ $alert->subscription->user->name ?? 'Unknown' }}
                                        <br>
                                        <span class="text-gray-500">{{ $alert->subscription->user->phone ?? '' }}</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($alert->clicked_at)
                                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Clicked</span>
                                        @elseif($alert->delivered_at)
                                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700">Delivered</span>
                                        @elseif($alert->sent_at)
                                            <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Sent</span>
                                        @elseif($alert->failed_at)
                                            <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Failed</span>
                                        @else
                                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Pending</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-500">
                                        {{ $alert->sent_at ? $alert->sent_at->format('H:i') : '-' }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-500">
                                        {{ $alert->click_action ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($catch->alerts->count() > 20)
                    <p class="mt-4 text-sm text-gray-500">Showing 20 of {{ $catch->alerts->count() }} alerts</p>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
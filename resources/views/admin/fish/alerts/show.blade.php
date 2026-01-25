@extends('admin.layouts.app')

@section('title', 'Alert Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.fish.alerts.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Alerts</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Alert Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto
                    @if($alert->clicked_at) bg-green-100
                    @elseif($alert->delivered_at) bg-blue-100
                    @elseif($alert->sent_at) bg-yellow-100
                    @elseif($alert->failed_at) bg-red-100
                    @else bg-gray-100
                    @endif">
                    <span class="text-3xl">
                        @if($alert->clicked_at) ✅
                        @elseif($alert->delivered_at) 📬
                        @elseif($alert->sent_at) 📤
                        @elseif($alert->failed_at) ❌
                        @else ⏳
                        @endif
                    </span>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">Alert #{{ $alert->id }}</h2>

                <div class="mt-4">
                    @if($alert->clicked_at)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Clicked</span>
                    @elseif($alert->delivered_at)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-blue-100 text-blue-700">Delivered</span>
                    @elseif($alert->sent_at)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-yellow-100 text-yellow-700">Sent</span>
                    @elseif($alert->failed_at)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Failed</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-gray-100 text-gray-700">Pending</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Created</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $alert->created_at->format('M j, Y H:i:s') }}</dd>
                    </div>
                    @if($alert->sent_at)
                    <div>
                        <dt class="text-sm text-gray-500">Sent</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $alert->sent_at->format('M j, Y H:i:s') }}</dd>
                    </div>
                    @endif
                    @if($alert->delivered_at)
                    <div>
                        <dt class="text-sm text-gray-500">Delivered</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $alert->delivered_at->format('M j, Y H:i:s') }}</dd>
                    </div>
                    @endif
                    @if($alert->clicked_at)
                    <div>
                        <dt class="text-sm text-gray-500">Clicked</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $alert->clicked_at->format('M j, Y H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Click Action</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $alert->click_action ?? 'N/A' }}</dd>
                    </div>
                    @endif
                    @if($alert->failed_at)
                    <div>
                        <dt class="text-sm text-gray-500">Failed</dt>
                        <dd class="text-sm font-medium text-red-600">{{ $alert->failed_at->format('M j, Y H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Failure Reason</dt>
                        <dd class="text-sm font-medium text-red-600">{{ $alert->failure_reason ?? 'Unknown' }}</dd>
                    </div>
                    @endif
                    @if($alert->whatsapp_message_id)
                    <div>
                        <dt class="text-sm text-gray-500">WhatsApp Message ID</dt>
                        <dd class="text-sm font-medium text-gray-800 break-all">{{ $alert->whatsapp_message_id }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            @if($alert->failed_at)
            <div class="mt-6 pt-6 border-t border-gray-200">
                <form method="POST" action="{{ route('admin.fish.alerts.retry', $alert) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg bg-green-100 text-green-700 hover:bg-green-200">
                        🔄 Retry Alert
                    </button>
                </form>
            </div>
            @endif
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Subscriber Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Subscriber</h3>
            <div class="flex items-center">
                <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                    <span class="text-purple-600 font-medium">{{ substr($alert->subscription->user->name ?? 'U', 0, 1) }}</span>
                </div>
                <div class="ml-4">
                    <p class="font-medium text-gray-800">{{ $alert->subscription->user->name ?? 'Unknown' }}</p>
                    <p class="text-sm text-gray-500">{{ $alert->subscription->user->phone ?? '' }}</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Frequency:</span>
                    <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $alert->subscription->frequency)) }}</span>
                </div>
                <div>
                    <span class="text-gray-500">Radius:</span>
                    <span class="font-medium">{{ $alert->subscription->radius_km }} km</span>
                </div>
            </div>
            <a href="{{ route('admin.fish.subscriptions.show', $alert->subscription) }}"
               class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View Subscription →</a>
        </div>

        <!-- Catch Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Fish Catch</h3>
            <div class="flex items-center">
                <span class="text-4xl mr-4">🐟</span>
                <div>
                    <p class="font-medium text-gray-800">{{ $alert->catch->fishType->name_en ?? 'Unknown' }}</p>
                    <p class="text-sm text-gray-500">{{ $alert->catch->fishType->name_ml ?? '' }}</p>
                    <p class="text-sm text-gray-500">{{ $alert->catch->catch_number }}</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Price:</span>
                    <span class="font-medium">₹{{ number_format($alert->catch->price_per_kg) }}/kg</span>
                </div>
                <div>
                    <span class="text-gray-500">Status:</span>
                    @php
                        $statusColors = [
                            'available' => 'bg-green-100 text-green-700',
                            'low_stock' => 'bg-yellow-100 text-yellow-700',
                            'sold_out' => 'bg-gray-100 text-gray-700',
                            'expired' => 'bg-red-100 text-red-700',
                        ];
                    @endphp
                    <span class="px-2 py-1 text-xs rounded-full {{ $statusColors[$alert->catch->status] ?? 'bg-gray-100' }}">
                        {{ ucfirst(str_replace('_', ' ', $alert->catch->status)) }}
                    </span>
                </div>
            </div>
            <a href="{{ route('admin.fish.catches.show', $alert->catch) }}"
               class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View Catch →</a>
        </div>

        <!-- Seller Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Seller</h3>
            <div class="flex items-center">
                <div class="w-12 h-12 bg-cyan-100 rounded-full flex items-center justify-center">
                    <span class="text-cyan-600 font-medium">{{ substr($alert->catch->seller->business_name ?? 'U', 0, 1) }}</span>
                </div>
                <div class="ml-4">
                    <p class="font-medium text-gray-800">{{ $alert->catch->seller->business_name ?? 'Unknown' }}</p>
                    <p class="text-sm text-gray-500">{{ $alert->catch->seller->user->phone ?? '' }}</p>
                </div>
            </div>
            <a href="{{ route('admin.fish.sellers.show', $alert->catch->seller) }}"
               class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View Seller →</a>
        </div>
    </div>
</div>
@endsection

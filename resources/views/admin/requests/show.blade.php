@extends('admin.layouts.app')

@section('title', 'Request Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.requests.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Requests</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Request Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">{{ $productRequest->request_number }}</h2>
                @php
                    $statusColors = [
                        'open' => 'bg-green-100 text-green-700',
                        'collecting' => 'bg-blue-100 text-blue-700',
                        'closed' => 'bg-gray-100 text-gray-700',
                        'expired' => 'bg-red-100 text-red-700',
                    ];
                    $color = $statusColors[$productRequest->status->value] ?? 'bg-gray-100 text-gray-700';
                @endphp
                <span class="px-3 py-1 text-sm font-medium rounded-full {{ $color }}">
                    {{ ucfirst($productRequest->status->value) }}
                </span>
            </div>

            <div class="bg-gray-50 rounded-lg p-4 mb-4">
                <p class="text-gray-800">{{ $productRequest->description }}</p>
            </div>

            @if($productRequest->image_url)
                <div class="mb-4">
                    <img src="{{ $productRequest->image_url }}" alt="" class="w-full rounded-lg">
                </div>
            @endif

            <dl class="space-y-4">
                <div>
                    <dt class="text-sm text-gray-500">Customer</dt>
                    <dd class="text-sm font-medium text-gray-800">
                        <a href="{{ route('admin.users.show', $productRequest->user) }}" class="text-blue-600 hover:text-blue-700">
                            {{ $productRequest->user->name ?? 'N/A' }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Phone</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $productRequest->user->phone ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Category</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ ucfirst($productRequest->category?->value ?? 'All') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Search Radius</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $productRequest->radius_km ?? 5 }} km</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Location</dt>
                    <dd class="text-sm font-medium text-gray-800">
                        {{ $productRequest->latitude }}, {{ $productRequest->longitude }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Shops Notified</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $productRequest->shops_notified ?? 0 }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Created</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $productRequest->created_at->format('M j, Y H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Expires</dt>
                    <dd class="text-sm font-medium text-gray-800">
                        {{ $productRequest->expires_at->format('M j, Y H:i') }}
                        @if($productRequest->expires_at < now())
                            <span class="text-red-500">(expired)</span>
                        @else
                            <span class="text-green-500">({{ $productRequest->expires_at->diffForHumans() }})</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if(in_array($productRequest->status->value, ['open', 'collecting']))
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <form method="POST" action="{{ route('admin.requests.close', $productRequest) }}">
                        @csrf
                        <button type="submit" class="w-full py-2 px-4 rounded-lg bg-yellow-100 text-yellow-700 hover:bg-yellow-200">
                            Close Request
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <!-- Responses -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">
                Responses ({{ $productRequest->responses->count() }})
            </h3>

            @if($productRequest->responses->isEmpty())
                <p class="text-gray-500 text-sm">No responses yet</p>
            @else
                <div class="space-y-4">
                    @foreach($productRequest->responses->sortBy('price') as $response)
                        <div class="p-4 border border-gray-200 rounded-lg {{ $response->is_available ? 'bg-white' : 'bg-gray-50' }}">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start">
                                    @if($response->image_url)
                                        <img src="{{ $response->image_url }}" alt="" class="w-20 h-20 rounded-lg object-cover">
                                    @else
                                        <div class="w-20 h-20 bg-gray-100 rounded-lg flex items-center justify-center">
                                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </div>
                                    @endif
                                    <div class="ml-4">
                                        <p class="font-medium text-gray-800">
                                            <a href="{{ route('admin.shops.show', $response->shop) }}" class="text-blue-600 hover:text-blue-700">
                                                {{ $response->shop->shop_name ?? 'Unknown Shop' }}
                                            </a>
                                        </p>
                                        <p class="text-sm text-gray-500">{{ $response->shop->owner->name ?? '' }}</p>
                                        @if($response->description)
                                            <p class="text-sm text-gray-600 mt-2">{{ $response->description }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right">
                                    @if($response->is_available)
                                        <p class="text-xl font-bold text-green-600">₹{{ number_format($response->price ?? 0) }}</p>
                                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Available</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Not Available</span>
                                    @endif
                                    <p class="text-xs text-gray-400 mt-2">{{ $response->created_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
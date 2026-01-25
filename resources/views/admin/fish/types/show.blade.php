@extends('admin.layouts.app')

@section('title', 'Fish Type Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.fish.types.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Fish Types</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Fish Type Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <span class="text-6xl">{{ $fishType->is_popular ? '⭐' : '🐟' }}</span>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $fishType->name_en }}</h2>
                <p class="text-gray-500">{{ $fishType->name_ml }}</p>

                <div class="mt-4 flex justify-center gap-2">
                    <span class="px-3 py-1 text-sm font-medium rounded-full bg-blue-100 text-blue-700">
                        {{ ucfirst($fishType->category) }}
                    </span>
                    @if($fishType->is_active)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Active</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    @if($fishType->scientific_name)
                    <div>
                        <dt class="text-sm text-gray-500">Scientific Name</dt>
                        <dd class="text-sm font-medium text-gray-800 italic">{{ $fishType->scientific_name }}</dd>
                    </div>
                    @endif
                    @if($fishType->local_names)
                    <div>
                        <dt class="text-sm text-gray-500">Local Names</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $fishType->local_names }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-sm text-gray-500">Created</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $fishType->created_at->format('F j, Y') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                <form method="POST" action="{{ route('admin.fish.types.toggle-popular', $fishType) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $fishType->is_popular ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' }}">
                        {{ $fishType->is_popular ? '★ Remove from Popular' : '☆ Mark as Popular' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.fish.types.toggle-active', $fishType) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $fishType->is_active ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        {{ $fishType->is_active ? 'Disable Fish Type' : 'Enable Fish Type' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- Price Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Price Statistics (30 Days)</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Minimum</dt>
                    <dd class="font-medium text-gray-800">₹{{ number_format($priceStats['min'] ?? 0) }}/kg</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Maximum</dt>
                    <dd class="font-medium text-gray-800">₹{{ number_format($priceStats['max'] ?? 0) }}/kg</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Average</dt>
                    <dd class="font-medium text-gray-800">₹{{ number_format($priceStats['avg'] ?? 0) }}/kg</dd>
                </div>
            </dl>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Catches</dt>
                    <dd class="font-medium text-gray-800">{{ $fishType->catches_count ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Active Catches</dt>
                    <dd class="font-medium text-green-600">{{ $fishType->active_catches_count ?? 0 }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Subscribers</dt>
                    <dd class="font-medium text-gray-800">{{ $fishType->subscriptions_count ?? 0 }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Edit Form -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Edit Fish Type</h3>
            <form method="POST" action="{{ route('admin.fish.types.update', $fishType) }}">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">English Name</label>
                        <input type="text" name="name_en" value="{{ $fishType->name_en }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Malayalam Name</label>
                        <input type="text" name="name_ml" value="{{ $fishType->name_ml }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Scientific Name</label>
                        <input type="text" name="scientific_name" value="{{ $fishType->scientific_name }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                            <option value="marine" {{ $fishType->category === 'marine' ? 'selected' : '' }}>Marine</option>
                            <option value="freshwater" {{ $fishType->category === 'freshwater' ? 'selected' : '' }}>Freshwater</option>
                            <option value="brackish" {{ $fishType->category === 'brackish' ? 'selected' : '' }}>Brackish</option>
                            <option value="shellfish" {{ $fishType->category === 'shellfish' ? 'selected' : '' }}>Shellfish</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Local Names</label>
                        <input type="text" name="local_names" value="{{ $fishType->local_names }}"
                               placeholder="Comma separated local names"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">{{ $fishType->description }}</textarea>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Recent Catches -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Recent Catches</h3>
            @if($recentCatches->isEmpty())
                <p class="text-gray-500 text-sm">No catches found for this fish type</p>
            @else
                <div class="space-y-4">
                    @foreach($recentCatches as $catch)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-800">{{ $catch->seller->business_name ?? 'Unknown Seller' }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $catch->catch_number }} • ₹{{ number_format($catch->price_per_kg) }}/kg
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-1 text-xs rounded-full {{ $catch->status === 'available' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' }}">
                                    {{ ucfirst($catch->status) }}
                                </span>
                                <p class="text-xs text-gray-400 mt-1">{{ $catch->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('admin.fish.catches.index', ['fish_type_id' => $fishType->id]) }}"
                   class="block mt-4 text-sm text-blue-600 hover:text-blue-700">View all catches →</a>
            @endif
        </div>
    </div>
</div>
@endsection

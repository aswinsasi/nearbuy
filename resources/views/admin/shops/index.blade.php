@extends('admin.layouts.app')

@section('title', 'Shops')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Shop name, owner..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->value }}" {{ request('category') === $category->value ? 'selected' : '' }}>
                        {{ ucfirst($category->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Verified</label>
            <select name="verified" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="yes" {{ request('verified') === 'yes' ? 'selected' : '' }}>Verified</option>
                <option value="no" {{ request('verified') === 'no' ? 'selected' : '' }}>Unverified</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Active</label>
            <select name="active" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="yes" {{ request('active') === 'yes' ? 'selected' : '' }}>Active</option>
                <option value="no" {{ request('active') === 'no' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>
    </form>
</div>

<!-- Shops Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shop</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stats</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($shops as $shop)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                                <span class="text-emerald-600 font-medium">{{ substr($shop->shop_name, 0, 1) }}</span>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">{{ $shop->shop_name }}</div>
                                <div class="text-sm text-gray-500">{{ Str::limit($shop->address, 30) }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                            {{ ucfirst($shop->category->value) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $shop->owner->name ?? 'N/A' }}</div>
                        <div class="text-sm text-gray-500">{{ $shop->owner->phone ?? '' }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex flex-col gap-1">
                            @if($shop->verified)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 w-fit">
                                    Verified
                                </span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700 w-fit">
                                    Unverified
                                </span>
                            @endif
                            @if(!$shop->is_active)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 w-fit">
                                    Inactive
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $shop->offers_count ?? 0 }} offers • {{ $shop->responses_count ?? 0 }} responses
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.shops.show', $shop) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        <form method="POST" action="{{ route('admin.shops.toggle-verification', $shop) }}" class="inline">
                            @csrf
                            <button type="submit" class="{{ $shop->verified ? 'text-yellow-600 hover:text-yellow-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $shop->verified ? 'Unverify' : 'Verify' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No shops found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($shops->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $shops->links() }}
        </div>
    @endif
</div>
@endsection
@extends('admin.layouts.app')

@section('title', 'Fish Types')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Name..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Categories</option>
                <option value="marine" {{ request('category') === 'marine' ? 'selected' : '' }}>Marine</option>
                <option value="freshwater" {{ request('category') === 'freshwater' ? 'selected' : '' }}>Freshwater</option>
                <option value="brackish" {{ request('category') === 'brackish' ? 'selected' : '' }}>Brackish</option>
                <option value="shellfish" {{ request('category') === 'shellfish' ? 'selected' : '' }}>Shellfish</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="active" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="true" {{ request('active') === 'true' ? 'selected' : '' }}>Active</option>
                <option value="false" {{ request('active') === 'false' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Popular</label>
            <select name="popular" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All</option>
                <option value="true" {{ request('popular') === 'true' ? 'selected' : '' }}>Popular Only</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
            <button type="button" onclick="openAddModal()" class="bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700">
                + Add
            </button>
        </div>
    </form>
</div>

<!-- Fish Types Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fish Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catches</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Price</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($fishTypes as $fishType)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="text-2xl mr-3">{{ $fishType->is_popular ? '⭐' : '🐟' }}</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">{{ $fishType->name_en }}</div>
                                <div class="text-sm text-gray-500">{{ $fishType->name_ml }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700">
                            {{ ucfirst($fishType->category) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $fishType->catches_count ?? 0 }} total
                        <br>
                        <span class="text-green-600">{{ $fishType->active_catches_count ?? 0 }} active</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        @if($fishType->avg_price_per_kg)
                            ₹{{ number_format($fishType->avg_price_per_kg) }}/kg
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($fishType->is_active)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">Active</span>
                        @else
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Inactive</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.fish.types.show', $fishType) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        <form method="POST" action="{{ route('admin.fish.types.toggle-popular', $fishType) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-yellow-600 hover:text-yellow-700 mr-3">
                                {{ $fishType->is_popular ? '★' : '☆' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.fish.types.toggle-active', $fishType) }}" class="inline">
                            @csrf
                            <button type="submit" class="{{ $fishType->is_active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $fishType->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No fish types found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($fishTypes->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $fishTypes->links() }}
        </div>
    @endif
</div>

<!-- Add Fish Type Modal -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl p-6 w-full max-w-md mx-4">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Add Fish Type</h3>
        <form method="POST" action="{{ route('admin.fish.types.store') }}">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">English Name</label>
                    <input type="text" name="name_en" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Malayalam Name</label>
                    <input type="text" name="name_ml" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="marine">Marine</option>
                        <option value="freshwater">Freshwater</option>
                        <option value="brackish">Brackish</option>
                        <option value="shellfish">Shellfish</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Local Names (comma separated)</label>
                    <input type="text" name="local_names" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div class="flex items-center">
                    <input type="checkbox" name="is_popular" id="is_popular" class="rounded border-gray-300">
                    <label for="is_popular" class="ml-2 text-sm text-gray-700">Mark as Popular</label>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeAddModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Add Fish Type</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
    document.getElementById('addModal').classList.add('flex');
}
function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
    document.getElementById('addModal').classList.remove('flex');
}
</script>
@endpush

@extends('admin.layouts.app')

@section('title', 'Users')

@section('content')
<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Name or phone..."
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Types</option>
                <option value="customer" {{ request('type') === 'customer' ? 'selected' : '' }}>Customers</option>
                <option value="shop_owner" {{ request('type') === 'shop_owner' ? 'selected' : '' }}>Shop Owners</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
            <input type="date" name="from_date" value="{{ request('from_date') }}"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex items-end">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
                Filter
            </button>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($users as $user)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                <span class="text-gray-600 font-medium">{{ substr($user->name, 0, 1) }}</span>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">{{ $user->name }}</div>
                                @if($user->shop)
                                    <div class="text-sm text-gray-500">{{ $user->shop->shop_name }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $user->phone }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($user->shop)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-700">
                                Shop Owner
                            </span>
                        @else
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700">
                                Customer
                            </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($user->is_active)
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">
                                Active
                            </span>
                        @else
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">
                                Suspended
                            </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $user->created_at->format('M j, Y') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="{{ route('admin.users.show', $user) }}" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                        <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}" class="inline">
                            @csrf
                            <button type="submit" class="{{ $user->is_active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}">
                                {{ $user->is_active ? 'Suspend' : 'Activate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No users found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Pagination -->
    @if($users->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $users->links() }}
        </div>
    @endif
</div>
@endsection
@extends('admin.layouts.app')

@section('title', 'User Details')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.users.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Users</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- User Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="text-center">
                <div class="w-20 h-20 bg-gray-200 rounded-full flex items-center justify-center mx-auto">
                    <span class="text-2xl text-gray-600 font-medium">{{ substr($user->name, 0, 1) }}</span>
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-800">{{ $user->name }}</h2>
                <p class="text-gray-500">{{ $user->phone }}</p>

                <div class="mt-4">
                    @if($user->is_active)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-green-100 text-green-700">Active</span>
                    @else
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700">Suspended</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Language</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $user->language === 'ml' ? 'Malayalam' : 'English' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Joined</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $user->created_at->format('F j, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Last Active</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $user->updated_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 space-y-2">
                <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 px-4 rounded-lg {{ $user->is_active ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        {{ $user->is_active ? 'Suspend User' : 'Activate User' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4">Statistics</h3>
            <dl class="space-y-4">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Requests</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_requests'] }}</dd>
                </div>
                @if($user->shop)
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Responses</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_responses'] }}</dd>
                </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-gray-500">Agreements</dt>
                    <dd class="font-medium text-gray-800">{{ $stats['total_agreements'] }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Shop Info (if applicable) -->
        @if($user->shop)
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Shop Details</h3>
                <a href="{{ route('admin.shops.show', $user->shop) }}" class="text-blue-600 hover:text-blue-700 text-sm">View Shop →</a>
            </div>

            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm text-gray-500">Shop Name</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $user->shop->shop_name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Category</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ ucfirst($user->shop->category->value) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Status</dt>
                    <dd>
                        @if($user->shop->verified)
                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Verified</span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Unverified</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Active Offers</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $user->shop->offers->where('is_active', true)->count() }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-sm text-gray-500">Address</dt>
                    <dd class="text-sm font-medium text-gray-800">{{ $user->shop->address }}</dd>
                </div>
            </dl>
        </div>
        @endif

        <!-- Recent Requests -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Recent Product Requests</h3>

            @if($requests->isEmpty())
                <p class="text-gray-500 text-sm">No product requests found</p>
            @else
                <div class="space-y-4">
                    @foreach($requests as $request)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-800">{{ Str::limit($request->description, 50) }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $request->request_number }} • {{ $request->responses->count() }} responses
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-1 text-xs rounded-full {{ $request->status->value === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' }}">
                                    {{ ucfirst($request->status->value) }}
                                </span>
                                <p class="text-xs text-gray-400 mt-1">{{ $request->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Agreements -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Agreements</h3>

            @if($agreements->isEmpty())
                <p class="text-gray-500 text-sm">No agreements found</p>
            @else
                <div class="space-y-4">
                    @foreach($agreements as $agreement)
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-800">₹{{ number_format($agreement->amount) }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $agreement->agreement_number }} •
                                    with {{ $agreement->from_user_id === $user->id ? $agreement->to_name : $agreement->fromUser->name }}
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-1 text-xs rounded-full {{ $agreement->status->value === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                    {{ ucfirst($agreement->status->value) }}
                                </span>
                                <p class="text-xs text-gray-400 mt-1">{{ $agreement->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
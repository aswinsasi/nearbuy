<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - NearBuy Admin</title>

    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Alpine.js for interactivity -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    @stack('styles')
</head>
<body class="bg-gray-100" x-data="{ sidebarOpen: true, mobileMenuOpen: false }">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 w-64 bg-gray-900 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static lg:inset-auto"
            :class="{ '-translate-x-full': !sidebarOpen && !mobileMenuOpen, 'translate-x-0': sidebarOpen || mobileMenuOpen }"
        >
            <!-- Logo -->
            <div class="flex items-center justify-between h-16 px-4 bg-gray-800">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center space-x-2">
                    <span class="text-2xl font-bold text-blue-400">Near</span>
                    <span class="text-2xl font-bold text-emerald-400">Buy</span>
                </a>
                <button @click="mobileMenuOpen = false" class="lg:hidden text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Navigation -->
            <nav class="mt-4 px-2">
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.dashboard*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Dashboard
                </a>

                <a href="{{ route('admin.users.index') }}"
                   class="flex items-center px-4 py-3 mt-1 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.users*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    Users
                </a>

                <a href="{{ route('admin.shops.index') }}"
                   class="flex items-center px-4 py-3 mt-1 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.shops*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    Shops
                </a>

                <a href="{{ route('admin.offers.index') }}"
                   class="flex items-center px-4 py-3 mt-1 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.offers*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                    Offers
                </a>

                <a href="{{ route('admin.requests.index') }}"
                   class="flex items-center px-4 py-3 mt-1 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.requests*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Requests
                </a>

                <a href="{{ route('admin.agreements.index') }}"
                   class="flex items-center px-4 py-3 mt-1 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.agreements*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Agreements
                </a>

                <div class="mt-8 pt-4 border-t border-gray-700">
                    <a href="{{ route('admin.settings.index') }}"
                       class="flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.settings*') ? 'bg-gray-800 text-white' : '' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Settings
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="flex items-center justify-between h-16 px-4">
                    <div class="flex items-center">
                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-600 hover:bg-gray-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <h1 class="ml-2 lg:ml-0 text-xl font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
                    </div>

                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-600">{{ auth('admin')->user()->name }}</span>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-red-600 hover:text-red-700">Logout</button>
                        </form>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-6 overflow-auto">
                <!-- Flash Messages -->
                @if(session('success'))
                    <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg" x-data="{ show: true }" x-show="show">
                        <div class="flex justify-between items-center">
                            <span>{{ session('success') }}</span>
                            <button @click="show = false" class="text-green-700 hover:text-green-900">&times;</button>
                        </div>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg" x-data="{ show: true }" x-show="show">
                        <div class="flex justify-between items-center">
                            <span>{{ session('error') }}</span>
                            <button @click="show = false" class="text-red-700 hover:text-red-900">&times;</button>
                        </div>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <!-- Overlay for mobile sidebar -->
    <div
        x-show="mobileMenuOpen"
        @click="mobileMenuOpen = false"
        class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
        x-cloak
    ></div>

    @stack('scripts')
</body>
</html>
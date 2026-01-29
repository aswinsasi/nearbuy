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
<body class="bg-gray-100" x-data="{ 
    sidebarOpen: true, 
    mobileMenuOpen: false, 
    fishMenuOpen: {{ request()->routeIs('admin.fish.*') ? 'true' : 'false' }},
    jobsMenuOpen: {{ request()->routeIs('admin.jobs.*') ? 'true' : 'false' }}
}">
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
            <nav class="mt-4 px-2 overflow-y-auto" style="max-height: calc(100vh - 4rem);">
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

                <!-- Fish Module Section -->
                <div class="mt-6 pt-4 border-t border-gray-700">
                    <button @click="fishMenuOpen = !fishMenuOpen"
                            class="flex items-center justify-between w-full px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.*') ? 'bg-gray-800 text-white' : '' }}">
                        <div class="flex items-center">
                            <span class="text-xl mr-3">🐟</span>
                            <span>Pacha Meen</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': fishMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="fishMenuOpen" x-collapse class="mt-1 ml-4 space-y-1">
                        <a href="{{ route('admin.fish.dashboard') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.dashboard') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                            </svg>
                            Dashboard
                        </a>
                        <a href="{{ route('admin.fish.types.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.types.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                            </svg>
                            Fish Types
                        </a>
                        <a href="{{ route('admin.fish.sellers.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.sellers.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Sellers
                        </a>
                        <a href="{{ route('admin.fish.catches.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.catches.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            Catches
                        </a>
                        <a href="{{ route('admin.fish.subscriptions.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.subscriptions.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            Subscriptions
                        </a>
                        <a href="{{ route('admin.fish.alerts.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.fish.alerts.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Alerts
                        </a>
                    </div>
                </div>

                <!-- Jobs Module Section (Njaanum Panikkar) -->
                <div class="mt-2">
                    <button @click="jobsMenuOpen = !jobsMenuOpen"
                            class="flex items-center justify-between w-full px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.jobs.*') ? 'bg-gray-800 text-white' : '' }}">
                        <div class="flex items-center">
                            <span class="text-xl mr-3">💼</span>
                            <span>Njaanum Panikkar</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': jobsMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="jobsMenuOpen" x-collapse class="mt-1 ml-4 space-y-1">
                        <a href="{{ route('admin.jobs.dashboard') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.jobs.dashboard') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                            </svg>
                            Dashboard
                        </a>
                        <a href="{{ route('admin.jobs.workers.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.jobs.workers.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Workers
                        </a>
                        <a href="{{ route('admin.jobs.posts.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.jobs.posts.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            Job Posts
                        </a>
                        <a href="{{ route('admin.jobs.categories.index') }}"
                           class="flex items-center px-4 py-2 text-sm text-gray-400 rounded-lg hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.jobs.categories.*') ? 'bg-gray-700 text-white' : '' }}">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            Categories
                        </a>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-700">
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
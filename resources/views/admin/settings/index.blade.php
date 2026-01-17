@extends('admin.layouts.app')

@section('title', 'Settings')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- General Settings -->
    <div class="lg:col-span-2 space-y-6">
        <!-- App Settings -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Application Settings</h3>

            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Search Radius (km)</label>
                        <input type="number" name="default_radius_km" 
                               value="{{ $settings['default_radius_km'] }}"
                               min="1" max="50" step="0.5"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Default radius for product search</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Request Expiry (hours)</label>
                        <input type="number" name="product_request_expiry_hours" 
                               value="{{ $settings['product_request_expiry_hours'] }}"
                               min="1" max="168"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">How long product requests remain active</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Default Offer Validity (days)</label>
                        <input type="number" name="offer_default_days" 
                               value="{{ $settings['offer_default_days'] }}"
                               min="1" max="90"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Default validity period for new offers</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Agreement Expiry (days)</label>
                        <input type="number" name="agreement_expiry_days" 
                               value="{{ $settings['agreement_expiry_days'] }}"
                               min="1" max="30"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Time limit for confirming agreements</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Offers per Shop</label>
                        <input type="number" name="max_offers_per_shop" 
                               value="{{ $settings['max_offers_per_shop'] }}"
                               min="1" max="50"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Maximum active offers allowed per shop</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Active Requests</label>
                        <input type="number" name="max_active_requests" 
                               value="{{ $settings['max_active_requests'] }}"
                               min="1" max="20"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Maximum active requests per user</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Categories Management -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Shop Categories</h3>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Icon</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">English</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Malayalam</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($categories as $category)
                            <tr>
                                <td class="px-4 py-3 text-xl">{{ $category['icon'] ?? '📦' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $category['id'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-800">{{ $category['label'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-800">{{ $category['label_ml'] ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    @if($category['active'] ?? true)
                                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Active</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm text-gray-500">
                Note: Category changes require code deployment. Contact developer to add/modify categories.
            </p>
        </div>

        <!-- Message Templates Preview -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Message Templates</h3>
            <p class="text-sm text-gray-500 mb-4">Preview of WhatsApp message templates used by the system.</p>

            <div class="space-y-4">
                @foreach($messageTemplates as $key => $template)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex justify-between items-start mb-2">
                            <h4 class="font-medium text-gray-800">{{ $template['name'] }}</h4>
                            <span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">{{ $key }}</span>
                        </div>
                        <pre class="text-sm text-gray-600 bg-gray-50 p-3 rounded-lg whitespace-pre-wrap font-mono">{{ $template['template'] }}</pre>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-sm text-gray-500">
                Message templates are managed in code. Contact developer to modify templates.
            </p>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>

            <div class="space-y-3">
                <form method="POST" action="{{ route('admin.settings.clear-cache') }}">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 text-left bg-gray-50 hover:bg-gray-100 rounded-lg flex items-center">
                        <span class="text-xl mr-3">🔄</span>
                        <div>
                            <p class="font-medium text-gray-800">Clear Cache</p>
                            <p class="text-xs text-gray-500">Clear application cache</p>
                        </div>
                    </button>
                </form>
            </div>
        </div>

        <!-- System Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">System Info</h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Laravel Version</dt>
                    <dd class="text-gray-800 font-mono">{{ app()->version() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">PHP Version</dt>
                    <dd class="text-gray-800 font-mono">{{ PHP_VERSION }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Environment</dt>
                    <dd class="text-gray-800 font-mono">{{ app()->environment() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Debug Mode</dt>
                    <dd>
                        @if(config('app.debug'))
                            <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">ON</span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">OFF</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Timezone</dt>
                    <dd class="text-gray-800 font-mono">{{ config('app.timezone') }}</dd>
                </div>
            </dl>
        </div>

        <!-- Statistics -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Database Stats</h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Users</dt>
                    <dd class="text-gray-800 font-semibold">{{ \App\Models\User::count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Shops</dt>
                    <dd class="text-gray-800 font-semibold">{{ \App\Models\Shop::count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Active Offers</dt>
                    <dd class="text-gray-800 font-semibold">{{ \App\Models\Offer::where('is_active', true)->count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Total Agreements</dt>
                    <dd class="text-gray-800 font-semibold">{{ \App\Models\Agreement::count() }}</dd>
                </div>
            </dl>
        </div>

        <!-- Danger Zone -->
        <div class="bg-white rounded-xl shadow-sm p-6 border-2 border-red-200">
            <h3 class="text-lg font-semibold text-red-600 mb-4">⚠️ Danger Zone</h3>

            <p class="text-sm text-gray-600 mb-4">
                These actions are irreversible. Use with caution.
            </p>

            <div class="space-y-3">
                <button type="button" disabled
                        class="w-full px-4 py-2 bg-red-50 text-red-400 rounded-lg cursor-not-allowed flex items-center">
                    <span class="text-xl mr-3">🗑️</span>
                    <div class="text-left">
                        <p class="font-medium">Purge Old Data</p>
                        <p class="text-xs">Disabled for safety</p>
                    </div>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
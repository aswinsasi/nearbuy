<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Admin settings controller.
 */
class SettingsController extends Controller
{
    /**
     * Show settings page.
     */
    public function index()
    {
        $settings = $this->getSettings();
        $categories = $this->getCategories();
        $messageTemplates = $this->getMessageTemplates();

        return view('admin.settings.index', compact('settings', 'categories', 'messageTemplates'));
    }

    /**
     * Update settings.
     */
    public function update(Request $request)
    {
        $request->validate([
            'default_radius_km' => 'required|numeric|min:1|max:50',
            'product_request_expiry_hours' => 'required|integer|min:1|max:168',
            'offer_default_days' => 'required|integer|min:1|max:90',
            'agreement_expiry_days' => 'required|integer|min:1|max:30',
            'max_offers_per_shop' => 'required|integer|min:1|max:50',
            'max_active_requests' => 'required|integer|min:1|max:20',
        ]);

        // Store settings in cache (or database)
        $settings = $request->only([
            'default_radius_km',
            'product_request_expiry_hours',
            'offer_default_days',
            'agreement_expiry_days',
            'max_offers_per_shop',
            'max_active_requests',
        ]);

        foreach ($settings as $key => $value) {
            Cache::forever("settings.{$key}", $value);
        }

        return back()->with('success', 'Settings updated successfully.');
    }

    /**
     * Update categories.
     */
    public function updateCategories(Request $request)
    {
        $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|string',
            'categories.*.label' => 'required|string',
            'categories.*.label_ml' => 'nullable|string',
            'categories.*.icon' => 'nullable|string',
            'categories.*.active' => 'boolean',
        ]);

        Cache::forever('settings.categories', $request->categories);

        return back()->with('success', 'Categories updated successfully.');
    }

    /**
     * Clear application cache.
     */
    public function clearCache()
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');

        return back()->with('success', 'Cache cleared successfully.');
    }

    /**
     * Get current settings.
     */
    protected function getSettings(): array
    {
        return [
            'default_radius_km' => Cache::get('settings.default_radius_km', config('nearbuy.default_radius_km', 5)),
            'product_request_expiry_hours' => Cache::get('settings.product_request_expiry_hours', config('nearbuy.products.request_expiry_hours', 24)),
            'offer_default_days' => Cache::get('settings.offer_default_days', config('nearbuy.offers.default_validity_days', 7)),
            'agreement_expiry_days' => Cache::get('settings.agreement_expiry_days', config('nearbuy.agreements.confirmation_expiry_days', 7)),
            'max_offers_per_shop' => Cache::get('settings.max_offers_per_shop', config('nearbuy.offers.max_per_shop', 10)),
            'max_active_requests' => Cache::get('settings.max_active_requests', config('nearbuy.products.max_active_requests', 5)),
        ];
    }

    /**
     * Get categories.
     */
    protected function getCategories(): array
    {
        return Cache::get('settings.categories', [
            ['id' => 'grocery', 'label' => 'Grocery', 'label_ml' => 'പലചരക്ക്', 'icon' => '🛒', 'active' => true],
            ['id' => 'electronics', 'label' => 'Electronics', 'label_ml' => 'ഇലക്ട്രോണിക്സ്', 'icon' => '📱', 'active' => true],
            ['id' => 'clothes', 'label' => 'Clothes & Fashion', 'label_ml' => 'വസ്ത്രങ്ങൾ', 'icon' => '👕', 'active' => true],
            ['id' => 'medical', 'label' => 'Medical & Pharmacy', 'label_ml' => 'മെഡിക്കൽ', 'icon' => '💊', 'active' => true],
            ['id' => 'mobile', 'label' => 'Mobile & Accessories', 'label_ml' => 'മൊബൈൽ', 'icon' => '📲', 'active' => true],
            ['id' => 'appliances', 'label' => 'Home Appliances', 'label_ml' => 'ഉപകരണങ്ങൾ', 'icon' => '🔌', 'active' => true],
            ['id' => 'furniture', 'label' => 'Furniture', 'label_ml' => 'ഫർണിച്ചർ', 'icon' => '🪑', 'active' => true],
            ['id' => 'hardware', 'label' => 'Hardware & Tools', 'label_ml' => 'ഹാർഡ്‌വെയർ', 'icon' => '🔧', 'active' => true],
            ['id' => 'stationery', 'label' => 'Stationery & Books', 'label_ml' => 'സ്റ്റേഷനറി', 'icon' => '📚', 'active' => true],
            ['id' => 'food', 'label' => 'Food & Restaurant', 'label_ml' => 'ഭക്ഷണം', 'icon' => '🍽️', 'active' => true],
        ]);
    }

    /**
     * Get message templates preview.
     */
    protected function getMessageTemplates(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome Message',
                'template' => "🙏 *Welcome to NearBuy!*\n\nYour local shopping assistant.\n\nType 'menu' to see options.",
            ],
            'new_request' => [
                'name' => 'New Product Request (to shops)',
                'template' => "🔔 *New Product Request*\n\n📦 *{description}*\n📍 {distance} away\n⏰ Expires in {expires_in}",
            ],
            'new_response' => [
                'name' => 'New Response (to customer)',
                'template' => "✅ *New Response!*\n\n🏪 {shop_name}\n💰 ₹{price}\n📍 {distance} away",
            ],
            'agreement_confirm' => [
                'name' => 'Agreement Confirmation Request',
                'template' => "📋 *Agreement Confirmation*\n\n{creator_name} wants to record an agreement:\n💰 ₹{amount}\n📝 {purpose}",
            ],
        ];
    }
}
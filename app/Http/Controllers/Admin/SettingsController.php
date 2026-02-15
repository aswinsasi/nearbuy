<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Admin Settings Controller.
 *
 * Manages platform-wide settings including:
 * - General settings (radius, expiry times)
 * - Categories management
 * - Flash Deals configuration
 * - Fish Market settings
 * - Worker/Jobs settings
 * - Message templates
 *
 * @module Admin
 */
class SettingsController extends Controller
{
    // =========================================================================
    // MAIN SETTINGS PAGE
    // =========================================================================

    /**
     * Show settings page.
     */
    public function index(): View
    {
        return view('admin.settings.index', [
            'settings' => $this->getGeneralSettings(),
            'flashDealSettings' => $this->getFlashDealSettings(),
            'fishMarketSettings' => $this->getFishMarketSettings(),
            'jobsSettings' => $this->getJobsSettings(),
            'categories' => $this->getCategories(),
            'notificationFrequencies' => NotificationFrequency::options(),
            'messageTemplates' => $this->getMessageTemplates(),
        ]);
    }

    // =========================================================================
    // GENERAL SETTINGS
    // =========================================================================

    /**
     * Update general settings.
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_radius_km' => 'required|numeric|min:1|max:50',
            'product_request_expiry_hours' => 'required|integer|min:1|max:168',
            'offer_default_days' => 'required|integer|min:1|max:90',
            'agreement_expiry_days' => 'required|integer|min:1|max:30',
            'max_offers_per_shop' => 'required|integer|min:1|max:50',
            'max_active_requests' => 'required|integer|min:1|max:20',
        ]);

        foreach ($validated as $key => $value) {
            Cache::forever("settings.{$key}", $value);
        }

        return back()->with('success', 'General settings updated successfully.');
    }

    /**
     * Get general settings.
     */
    protected function getGeneralSettings(): array
    {
        return [
            'default_radius_km' => Cache::get('settings.default_radius_km', config('nearbuy.default_radius_km', 5)),
            'product_request_expiry_hours' => Cache::get('settings.product_request_expiry_hours', 24),
            'offer_default_days' => Cache::get('settings.offer_default_days', 7),
            'agreement_expiry_days' => Cache::get('settings.agreement_expiry_days', 7),
            'max_offers_per_shop' => Cache::get('settings.max_offers_per_shop', 10),
            'max_active_requests' => Cache::get('settings.max_active_requests', 5),
        ];
    }

    // =========================================================================
    // FLASH DEALS SETTINGS
    // =========================================================================

    /**
     * Update Flash Deals settings.
     */
    public function updateFlashDeals(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'flash_default_radius_km' => 'required|numeric|min:1|max:10',
            'flash_min_discount' => 'required|integer|min:5|max:50',
            'flash_max_discount' => 'required|integer|min:50|max:90',
            'flash_min_target' => 'required|integer|min:5|max:50',
            'flash_max_target' => 'required|integer|min:20|max:100',
            'flash_time_options' => 'required|array|min:2',
            'flash_time_options.*' => 'required|integer|min:10|max:180',
            'flash_target_options' => 'required|array|min:2',
            'flash_target_options.*' => 'required|integer|min:5|max:100',
            'flash_rescue_threshold' => 'required|integer|min:60|max:95',
            'flash_rescue_time_seconds' => 'required|integer|min:60|max:600',
            'flash_coupon_prefix' => 'required|string|max:10|alpha_num',
        ]);

        foreach ($validated as $key => $value) {
            Cache::forever("settings.{$key}", $value);
        }

        return back()->with('success', 'Flash Deals settings updated successfully.');
    }

    /**
     * Get Flash Deals settings.
     */
    protected function getFlashDealSettings(): array
    {
        return [
            'flash_default_radius_km' => Cache::get('settings.flash_default_radius_km', 3),
            'flash_min_discount' => Cache::get('settings.flash_min_discount', 5),
            'flash_max_discount' => Cache::get('settings.flash_max_discount', 90),
            'flash_min_target' => Cache::get('settings.flash_min_target', 10),
            'flash_max_target' => Cache::get('settings.flash_max_target', 50),
            'flash_time_options' => Cache::get('settings.flash_time_options', [15, 30, 60, 120]),
            'flash_target_options' => Cache::get('settings.flash_target_options', [10, 20, 30, 50]),
            'flash_rescue_threshold' => Cache::get('settings.flash_rescue_threshold', 80),
            'flash_rescue_time_seconds' => Cache::get('settings.flash_rescue_time_seconds', 300),
            'flash_coupon_prefix' => Cache::get('settings.flash_coupon_prefix', 'FLASH'),
        ];
    }

    // =========================================================================
    // FISH MARKET SETTINGS
    // =========================================================================

    /**
     * Update Fish Market settings.
     */
    public function updateFishMarket(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fish_default_radius_km' => 'required|numeric|min:1|max:20',
            'fish_alert_advance_minutes' => 'required|integer|min:5|max:60',
            'fish_seller_verification_required' => 'required|boolean',
            'fish_max_daily_broadcasts' => 'required|integer|min:1|max:10',
        ]);

        foreach ($validated as $key => $value) {
            Cache::forever("settings.{$key}", $value);
        }

        return back()->with('success', 'Fish Market settings updated successfully.');
    }

    /**
     * Get Fish Market settings.
     */
    protected function getFishMarketSettings(): array
    {
        return [
            'fish_default_radius_km' => Cache::get('settings.fish_default_radius_km', 5),
            'fish_alert_advance_minutes' => Cache::get('settings.fish_alert_advance_minutes', 15),
            'fish_seller_verification_required' => Cache::get('settings.fish_seller_verification_required', false),
            'fish_max_daily_broadcasts' => Cache::get('settings.fish_max_daily_broadcasts', 3),
        ];
    }

    // =========================================================================
    // JOBS/WORKER SETTINGS
    // =========================================================================

    /**
     * Update Jobs settings.
     */
    public function updateJobs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jobs_default_radius_km' => 'required|numeric|min:1|max:30',
            'jobs_expiry_hours' => 'required|integer|min:1|max:72',
            'jobs_max_applications' => 'required|integer|min:1|max:20',
            'jobs_worker_verification_required' => 'required|boolean',
            'jobs_min_price' => 'required|integer|min:50|max:500',
        ]);

        foreach ($validated as $key => $value) {
            Cache::forever("settings.{$key}", $value);
        }

        return back()->with('success', 'Jobs settings updated successfully.');
    }

    /**
     * Get Jobs settings.
     */
    protected function getJobsSettings(): array
    {
        return [
            'jobs_default_radius_km' => Cache::get('settings.jobs_default_radius_km', 10),
            'jobs_expiry_hours' => Cache::get('settings.jobs_expiry_hours', 24),
            'jobs_max_applications' => Cache::get('settings.jobs_max_applications', 5),
            'jobs_worker_verification_required' => Cache::get('settings.jobs_worker_verification_required', false),
            'jobs_min_price' => Cache::get('settings.jobs_min_price', 100),
        ];
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    /**
     * Update categories.
     */
    public function updateCategories(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|string|max:50',
            'categories.*.label' => 'required|string|max:50',
            'categories.*.label_ml' => 'nullable|string|max:100',
            'categories.*.icon' => 'nullable|string|max:10',
            'categories.*.active' => 'boolean',
        ]);

        Cache::forever('settings.categories', $validated['categories']);

        return back()->with('success', 'Categories updated successfully.');
    }

    /**
     * Get categories.
     */
    protected function getCategories(): array
    {
        // Get from ShopCategory enum as default
        $defaultCategories = collect(ShopCategory::cases())->map(fn($cat) => [
            'id' => $cat->value,
            'label' => $cat->label(),
            'label_ml' => $cat->labelMl(),
            'icon' => $cat->icon(),
            'active' => true,
        ])->toArray();

        return Cache::get('settings.categories', $defaultCategories);
    }

    // =========================================================================
    // MESSAGE TEMPLATES
    // =========================================================================

    /**
     * Update message templates.
     */
    public function updateTemplates(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'templates' => 'required|array',
            'templates.*.key' => 'required|string|max:50',
            'templates.*.name' => 'required|string|max:100',
            'templates.*.template_en' => 'required|string|max:1000',
            'templates.*.template_ml' => 'nullable|string|max:1000',
        ]);

        Cache::forever('settings.message_templates', $validated['templates']);

        return back()->with('success', 'Message templates updated successfully.');
    }

    /**
     * Get message templates.
     */
    protected function getMessageTemplates(): array
    {
        return Cache::get('settings.message_templates', [
            [
                'key' => 'welcome',
                'name' => 'Welcome Message',
                'template_en' => "🙏 *Welcome to NearBuy!*\n\nYour local shopping assistant.\n\nType 'menu' to see options.",
                'template_ml' => "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\nനിങ്ങളുടെ ലോക്കൽ ഷോപ്പിംഗ് അസിസ്റ്റന്റ്.\n\n'menu' ടൈപ്പ് ചെയ്യുക.",
            ],
            [
                'key' => 'new_request',
                'name' => 'New Product Request (to shops)',
                'template_en' => "🔔 *New Product Request*\n\n📦 *{description}*\n📍 {distance} away\n⏰ Expires in {expires_in}",
                'template_ml' => "🔔 *പുതിയ പ്രൊഡക്ട് റിക്വസ്റ്റ്*\n\n📦 *{description}*\n📍 {distance} അകലെ\n⏰ {expires_in} ൽ എക്സ്പയർ",
            ],
            [
                'key' => 'new_response',
                'name' => 'New Response (to customer)',
                'template_en' => "✅ *New Response!*\n\n🏪 {shop_name}\n💰 ₹{price}\n📍 {distance} away",
                'template_ml' => "✅ *പുതിയ മറുപടി!*\n\n🏪 {shop_name}\n💰 ₹{price}\n📍 {distance} അകലെ",
            ],
            [
                'key' => 'flash_deal_alert',
                'name' => 'Flash Deal Alert',
                'template_en' => "⚡ *FLASH DEAL!*\n\n🎯 {title}\n💰 {discount}% OFF\n👥 {current}/{target} claimed\n⏰ {time_remaining}",
                'template_ml' => "⚡ *ഫ്ലാഷ് ഡീൽ!*\n\n🎯 {title}\n💰 {discount}% ഓഫ്\n👥 {current}/{target} ക്ലെയിം\n⏰ {time_remaining}",
            ],
            [
                'key' => 'deal_activated',
                'name' => 'Flash Deal Activated',
                'template_en' => "🎉 *DEAL ACTIVATED!*\n\n🎫 Your coupon: {coupon_code}\n🏪 {shop_name}\n⏰ Valid until: {valid_until}",
                'template_ml' => "🎉 *ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n🎫 നിങ്ങളുടെ കൂപ്പൺ: {coupon_code}\n🏪 {shop_name}\n⏰ വരെ സാധുത: {valid_until}",
            ],
        ]);
    }

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Clear all caches.
     */
    public function clearCache(): RedirectResponse
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');

        return back()->with('success', 'All caches cleared successfully.');
    }

    /**
     * Clear settings cache only.
     */
    public function clearSettingsCache(): RedirectResponse
    {
        // Get all settings keys and forget them
        $settingsKeys = [
            'settings.default_radius_km',
            'settings.product_request_expiry_hours',
            'settings.offer_default_days',
            'settings.agreement_expiry_days',
            'settings.max_offers_per_shop',
            'settings.max_active_requests',
            'settings.categories',
            'settings.message_templates',
            // Flash deals
            'settings.flash_default_radius_km',
            'settings.flash_min_discount',
            'settings.flash_max_discount',
            'settings.flash_time_options',
            'settings.flash_target_options',
            'settings.flash_rescue_threshold',
            'settings.flash_coupon_prefix',
            // Fish market
            'settings.fish_default_radius_km',
            'settings.fish_alert_advance_minutes',
            // Jobs
            'settings.jobs_default_radius_km',
            'settings.jobs_expiry_hours',
        ];

        foreach ($settingsKeys as $key) {
            Cache::forget($key);
        }

        return back()->with('success', 'Settings cache cleared. Defaults will be used.');
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get settings statistics for dashboard.
     */
    public function getStatistics(): array
    {
        return [
            'total_shops' => DB::table('shops')->count(),
            'active_shops' => DB::table('shops')->where('is_active', true)->count(),
            'total_users' => DB::table('users')->count(),
            'total_flash_deals' => DB::table('flash_deals')->count(),
            'active_flash_deals' => DB::table('flash_deals')->where('status', 'live')->count(),
            'total_fish_sellers' => DB::table('fish_sellers')->count(),
            'total_workers' => DB::table('workers')->count(),
        ];
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get a setting value with default.
     */
    public static function get(string $key, $default = null)
    {
        return Cache::get("settings.{$key}", $default);
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, $value): void
    {
        Cache::forever("settings.{$key}", $value);
    }

    /**
     * Get Flash Deal configuration for use in services.
     */
    public static function getFlashDealConfig(): array
    {
        return [
            'radius_km' => self::get('flash_default_radius_km', 3),
            'min_discount' => self::get('flash_min_discount', 5),
            'max_discount' => self::get('flash_max_discount', 90),
            'time_options' => self::get('flash_time_options', [15, 30, 60, 120]),
            'target_options' => self::get('flash_target_options', [10, 20, 30, 50]),
            'rescue_threshold' => self::get('flash_rescue_threshold', 80),
            'rescue_time_seconds' => self::get('flash_rescue_time_seconds', 300),
            'coupon_prefix' => self::get('flash_coupon_prefix', 'FLASH'),
        ];
    }
}
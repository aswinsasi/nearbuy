<?php

use App\Http\Controllers\Admin\AgreementController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FishAlertController;
use App\Http\Controllers\Admin\FishCatchController;
use App\Http\Controllers\Admin\FishDashboardController;
use App\Http\Controllers\Admin\FishSellerController;
use App\Http\Controllers\Admin\FishSubscriptionController;
use App\Http\Controllers\Admin\FishTypeController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\ProductRequestController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Routes for the admin panel. All routes are prefixed with 'admin'.
|
| Add to RouteServiceProvider or bootstrap/app.php:
| Route::middleware('web')->prefix('admin')->group(base_path('routes/admin.php'));
|
*/

// Auth routes (no middleware)
Route::get('login', [AuthController::class, 'showLogin'])->name('admin.login');
Route::post('login', [AuthController::class, 'login'])->name('admin.login.submit');
Route::post('logout', [AuthController::class, 'logout'])->name('admin.logout');

// Protected admin routes
Route::middleware('admin.auth')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('admin.dashboard.index');

    // Users
    Route::get('users', [UserController::class, 'index'])->name('admin.users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('admin.users.show');
    Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('admin.users.toggle-status');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');

    // Shops
    Route::get('shops', [ShopController::class, 'index'])->name('admin.shops.index');
    Route::get('shops/{shop}', [ShopController::class, 'show'])->name('admin.shops.show');
    Route::post('shops/{shop}/toggle-verification', [ShopController::class, 'toggleVerification'])->name('admin.shops.toggle-verification');
    Route::post('shops/{shop}/toggle-active', [ShopController::class, 'toggleActive'])->name('admin.shops.toggle-active');

    // Offers
    Route::get('offers', [OfferController::class, 'index'])->name('admin.offers.index');
    Route::get('offers/{offer}', [OfferController::class, 'show'])->name('admin.offers.show');
    Route::post('offers/{offer}/toggle-active', [OfferController::class, 'toggleActive'])->name('admin.offers.toggle-active');
    Route::delete('offers/{offer}', [OfferController::class, 'destroy'])->name('admin.offers.destroy');

    // Product Requests
    Route::get('requests', [ProductRequestController::class, 'index'])->name('admin.requests.index');
    Route::get('requests/{productRequest}', [ProductRequestController::class, 'show'])->name('admin.requests.show');
    Route::post('requests/{productRequest}/close', [ProductRequestController::class, 'close'])->name('admin.requests.close');
    Route::delete('requests/{productRequest}', [ProductRequestController::class, 'destroy'])->name('admin.requests.destroy');

    // Agreements
    Route::get('agreements', [AgreementController::class, 'index'])->name('admin.agreements.index');
    Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->name('admin.agreements.show');
    Route::get('agreements/{agreement}/pdf', [AgreementController::class, 'downloadPdf'])->name('admin.agreements.pdf');
    Route::post('agreements/{agreement}/resolve', [AgreementController::class, 'resolveDispute'])->name('admin.agreements.resolve');
    Route::post('agreements/{agreement}/cancel', [AgreementController::class, 'cancel'])->name('admin.agreements.cancel');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('admin.settings.index');
    Route::post('settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::post('settings/categories', [SettingsController::class, 'updateCategories'])->name('admin.settings.categories');
    Route::post('settings/clear-cache', [SettingsController::class, 'clearCache'])->name('admin.settings.clear-cache');

    /*
    |--------------------------------------------------------------------------
    | Fish Module (Pacha Meen) Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('fish')->name('admin.fish.')->group(function () {

        // Fish Types
        Route::get('types', [FishTypeController::class, 'index'])->name('types.index');
        Route::post('types', [FishTypeController::class, 'store'])->name('types.store');
        Route::get('types/stats', [FishTypeController::class, 'stats'])->name('types.stats');
        Route::get('types/categories', [FishTypeController::class, 'categories'])->name('types.categories');
        Route::get('types/{fishType}', [FishTypeController::class, 'show'])->name('types.show');
        Route::put('types/{fishType}', [FishTypeController::class, 'update'])->name('types.update');
        Route::delete('types/{fishType}', [FishTypeController::class, 'destroy'])->name('types.destroy');
        Route::post('types/{fishType}/toggle-popular', [FishTypeController::class, 'togglePopular'])->name('types.toggle-popular');
        Route::post('types/{fishType}/toggle-active', [FishTypeController::class, 'toggleActive'])->name('types.toggle-active');

        // Fish Sellers
        Route::get('sellers', [FishSellerController::class, 'index'])->name('sellers.index');
        Route::get('sellers/stats', [FishSellerController::class, 'stats'])->name('sellers.stats');
        Route::get('sellers/{seller}', [FishSellerController::class, 'show'])->name('sellers.show');
        Route::put('sellers/{seller}', [FishSellerController::class, 'update'])->name('sellers.update');
        Route::post('sellers/{seller}/verify', [FishSellerController::class, 'verify'])->name('sellers.verify');
        Route::post('sellers/{seller}/deactivate', [FishSellerController::class, 'deactivate'])->name('sellers.deactivate');
        Route::post('sellers/{seller}/reactivate', [FishSellerController::class, 'reactivate'])->name('sellers.reactivate');

        // Fish Catches
        Route::get('catches', [FishCatchController::class, 'index'])->name('catches.index');
        Route::get('catches/stats', [FishCatchController::class, 'stats'])->name('catches.stats');
        Route::post('catches/expire-stale', [FishCatchController::class, 'expireStale'])->name('catches.expire-stale');
        Route::get('catches/{catch}', [FishCatchController::class, 'show'])->name('catches.show');
        Route::put('catches/{catch}/status', [FishCatchController::class, 'updateStatus'])->name('catches.update-status');
        Route::post('catches/{catch}/extend-expiry', [FishCatchController::class, 'extendExpiry'])->name('catches.extend-expiry');
        Route::delete('catches/{catch}', [FishCatchController::class, 'destroy'])->name('catches.destroy');

        // Fish Subscriptions
        Route::get('subscriptions', [FishSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('subscriptions/stats', [FishSubscriptionController::class, 'stats'])->name('subscriptions.stats');
        Route::get('subscriptions/{subscription}', [FishSubscriptionController::class, 'show'])->name('subscriptions.show');
        Route::post('subscriptions/{subscription}/deactivate', [FishSubscriptionController::class, 'deactivate'])->name('subscriptions.deactivate');
        Route::post('subscriptions/{subscription}/activate', [FishSubscriptionController::class, 'activate'])->name('subscriptions.activate');
        Route::delete('subscriptions/{subscription}', [FishSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');

        // Fish Alerts
        Route::get('alerts', [FishAlertController::class, 'index'])->name('alerts.index');
        Route::get('alerts/stats', [FishAlertController::class, 'stats'])->name('alerts.stats');
        Route::get('alerts/analytics', [FishAlertController::class, 'analytics'])->name('alerts.analytics');
        Route::post('alerts/process-pending', [FishAlertController::class, 'processPending'])->name('alerts.process-pending');
        Route::post('alerts/cleanup', [FishAlertController::class, 'cleanup'])->name('alerts.cleanup');
        Route::get('alerts/{alert}', [FishAlertController::class, 'show'])->name('alerts.show');
        Route::post('alerts/{alert}/retry', [FishAlertController::class, 'retry'])->name('alerts.retry');

        // Fish Dashboard
        Route::get('dashboard', [FishDashboardController::class, 'index'])->name('dashboard');
    });
});
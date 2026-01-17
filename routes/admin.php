<?php

use App\Http\Controllers\Admin\AgreementController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
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
});
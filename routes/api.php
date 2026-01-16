<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Middleware\VerifyWhatsAppWebhook;

/*
|--------------------------------------------------------------------------
| NearBuy API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

/*
|--------------------------------------------------------------------------
| WhatsApp Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhook events from WhatsApp Cloud API.
| The GET route is for webhook verification during setup.
| The POST route receives incoming messages and status updates.
|
*/

Route::prefix('webhook')->group(function () {

    // WhatsApp Webhook Verification (GET request from Meta)
    // This is called once when you register the webhook URL in Meta Developer Console
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhook.whatsapp.verify');

    // WhatsApp Webhook Handler (POST request for incoming events)
    // Protected by signature verification middleware
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->middleware(VerifyWhatsAppWebhook::class)
        ->name('webhook.whatsapp.handle');
});

/*
|--------------------------------------------------------------------------
| Health Check Route
|--------------------------------------------------------------------------
|
| Simple health check endpoint for monitoring and load balancers.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'nearbuy-api',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

/*
|--------------------------------------------------------------------------
| Agreement Verification Route (Public)
|--------------------------------------------------------------------------
|
| Public endpoint to verify agreement authenticity via QR code.
| Accessed when someone scans the QR code on an agreement PDF.
|
*/

Route::get('/agreements/{uuid}/verify', function (string $uuid) {
    // This will be implemented in the Agreement controller
    // Returns agreement verification status
    return response()->json([
        'message' => 'Agreement verification endpoint',
        'uuid' => $uuid,
    ]);
})->name('agreements.verify');

/*
|--------------------------------------------------------------------------
| Admin API Routes (Protected)
|--------------------------------------------------------------------------
|
| These routes are for admin dashboard and management.
| Protected by Sanctum authentication.
|
*/

Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {

    // Dashboard Statistics
    Route::get('/stats', function () {
        return response()->json([
            'message' => 'Admin stats endpoint - implement AdminController',
        ]);
    })->name('admin.stats');

    // User Management
    Route::prefix('users')->group(function () {
        Route::get('/', function () {
            return response()->json(['message' => 'List users']);
        })->name('admin.users.index');

        Route::get('/{id}', function ($id) {
            return response()->json(['message' => 'Show user', 'id' => $id]);
        })->name('admin.users.show');
    });

    // Shop Management
    Route::prefix('shops')->group(function () {
        Route::get('/', function () {
            return response()->json(['message' => 'List shops']);
        })->name('admin.shops.index');

        Route::get('/{id}', function ($id) {
            return response()->json(['message' => 'Show shop', 'id' => $id]);
        })->name('admin.shops.show');

        Route::patch('/{id}/status', function ($id) {
            return response()->json(['message' => 'Update shop status', 'id' => $id]);
        })->name('admin.shops.status');
    });

    // Agreement Management
    Route::prefix('agreements')->group(function () {
        Route::get('/', function () {
            return response()->json(['message' => 'List agreements']);
        })->name('admin.agreements.index');

        Route::get('/{uuid}', function ($uuid) {
            return response()->json(['message' => 'Show agreement', 'uuid' => $uuid]);
        })->name('admin.agreements.show');
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/offers', function () {
            return response()->json(['message' => 'Offer analytics']);
        })->name('admin.analytics.offers');

        Route::get('/requests', function () {
            return response()->json(['message' => 'Product request analytics']);
        })->name('admin.analytics.requests');

        Route::get('/agreements', function () {
            return response()->json(['message' => 'Agreement analytics']);
        })->name('admin.analytics.agreements');
    });
});

/*
|--------------------------------------------------------------------------
| Debug Routes (Development Only)
|--------------------------------------------------------------------------
|
| These routes are only available in local/development environment.
|
*/

if (app()->environment('local')) {
    Route::prefix('debug')->group(function () {

        // Simulate incoming WhatsApp message
        Route::post('/simulate-message', function (Request $request) {
            return response()->json([
                'message' => 'Message simulation endpoint',
                'payload' => $request->all(),
            ]);
        })->name('debug.simulate');

        // Test WhatsApp API connection
        Route::get('/test-whatsapp', function () {
            return response()->json([
                'message' => 'WhatsApp API test endpoint',
                'config' => [
                    'phone_number_id' => config('whatsapp.api.phone_number_id'),
                    'api_version' => config('whatsapp.api.version'),
                ],
            ]);
        })->name('debug.whatsapp');

        // View current configuration
        Route::get('/config', function () {
            return response()->json([
                'nearbuy' => config('nearbuy'),
                'whatsapp' => [
                    'api_version' => config('whatsapp.api.version'),
                    'limits' => config('whatsapp.limits'),
                ],
            ]);
        })->name('debug.config');
    });
}
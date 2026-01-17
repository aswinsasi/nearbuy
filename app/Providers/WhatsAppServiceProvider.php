<?php

namespace App\Providers;

use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for WhatsApp integration.
 *
 * Registers the WhatsAppService as a singleton and sets up
 * any related configurations.
 */
class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register WhatsAppService as singleton
        $this->app->singleton(WhatsAppService::class, function ($app) {
            return new WhatsAppService();
        });

        // Register an alias for easier access
        $this->app->alias(WhatsAppService::class, 'whatsapp');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/whatsapp.php' => config_path('whatsapp.php'),
        ], 'whatsapp-config');

        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/whatsapp.php',
            'whatsapp'
        );

        // Configure logging channel if not exists
        $this->configureLogging();
    }

    /**
     * Configure WhatsApp logging channel.
     */
    private function configureLogging(): void
    {
        $loggingConfig = config('logging.channels');

        if (!isset($loggingConfig['whatsapp'])) {
            config(['logging.channels.whatsapp' => [
                'driver' => 'daily',
                'path' => storage_path('logs/whatsapp.log'),
                'level' => env('LOG_LEVEL', 'debug'),
                'days' => 14,
            ]]);
        }
    }
}
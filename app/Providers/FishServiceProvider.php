<?php

namespace App\Providers;

use App\Services\Fish\FishAlertService;
use App\Services\Fish\FishCatchService;
use App\Services\Fish\FishMatchingService;
use App\Services\Fish\FishSellerService;
use App\Services\Fish\FishSubscriptionService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for Pacha Meen (Fish Alert) module.
 *
 * Registers all fish-related services.
 */
class FishServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register as singletons for performance
        $this->app->singleton(FishSellerService::class, function ($app) {
            return new FishSellerService();
        });

        $this->app->singleton(FishCatchService::class, function ($app) {
            return new FishCatchService();
        });

        $this->app->singleton(FishSubscriptionService::class, function ($app) {
            return new FishSubscriptionService();
        });

        $this->app->singleton(FishMatchingService::class, function ($app) {
            return new FishMatchingService();
        });

        $this->app->singleton(FishAlertService::class, function ($app) {
            return new FishAlertService(
                $app->make(FishSubscriptionService::class)
            );
        });

        // Register aliases for convenience
        $this->app->alias(FishSellerService::class, 'fish.seller');
        $this->app->alias(FishCatchService::class, 'fish.catch');
        $this->app->alias(FishSubscriptionService::class, 'fish.subscription');
        $this->app->alias(FishMatchingService::class, 'fish.matching');
        $this->app->alias(FishAlertService::class, 'fish.alert');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            FishSellerService::class,
            FishCatchService::class,
            FishSubscriptionService::class,
            FishMatchingService::class,
            FishAlertService::class,
            'fish.seller',
            'fish.catch',
            'fish.subscription',
            'fish.matching',
            'fish.alert',
        ];
    }
}

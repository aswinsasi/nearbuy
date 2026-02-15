<?php

use App\Providers\AppServiceProvider;
use App\Providers\FishServiceProvider;
use App\Providers\WhatsAppServiceProvider;
use App\Providers\QueueRateLimiterServiceProvider;

return [
    AppServiceProvider::class,
    WhatsAppServiceProvider::class,
    FishServiceProvider::class,
    QueueRateLimiterServiceProvider::class,
];

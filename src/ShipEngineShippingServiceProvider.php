<?php

declare(strict_types=1);

namespace Liberu\Shipping\ShipEngine;

use Illuminate\Support\ServiceProvider;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderRegistryContract;

final class ShipEngineShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShipEngineShippingAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(ShippingProviderRegistryContract::class)) {
            $this->app->make(ShippingProviderRegistryContract::class)->register(
                $this->app->make(ShipEngineShippingAdapter::class),
            );
        }
    }
}

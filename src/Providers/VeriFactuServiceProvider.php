<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Providers;

use Illuminate\Support\ServiceProvider;

class VeriFactuServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/verifactu.php', 'verifactu');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/verifactu.php' => config_path('verifactu.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
} 
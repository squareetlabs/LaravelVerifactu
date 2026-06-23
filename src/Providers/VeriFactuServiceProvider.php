<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Providers;

use Illuminate\Support\ServiceProvider;
use Squareetlabs\VeriFactu\Contracts\CertificateProvider;
use Squareetlabs\VeriFactu\Services\AeatClientFactory;
use Squareetlabs\VeriFactu\Services\ConfigCertificateProvider;

class VeriFactuServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/verifactu.php', 'verifactu');

        // Certificado por defecto desde config (plataforma / colaborador social).
        // Los hosts con otro almacén (por emisor, Vault, S3+KMS...) re-vinculan
        // CertificateProvider a su propia implementación.
        $this->app->bind(CertificateProvider::class, ConfigCertificateProvider::class);

        $this->app->singleton(AeatClientFactory::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publicar archivos de configuración
            $this->publishes([
                __DIR__ . '/../../config/verifactu.php' => config_path('verifactu.php'),
            ], 'verifactu-config');

            // Publicar migraciones solo si está habilitado en config
            if (config('verifactu.load_migrations', false)) {
                $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
            }

            // Registrar comandos
            $this->commands([
                \Squareetlabs\VeriFactu\Console\Commands\MakeAdapterCommand::class,
            ]);
        }
    }
}
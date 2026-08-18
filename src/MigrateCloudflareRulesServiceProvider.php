<?php

namespace Padosoft\MigrateCloudflareRules;

use Illuminate\Support\ServiceProvider;
use Padosoft\MigrateCloudflareRules\Console\Commands\MigrateCloudflareRules;

class MigrateCloudflareRulesServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/migrate-cloudflare-rules.php',
            'migrate-cloudflare-rules'
        );
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // php artisan vendor:publish --tag=migrate-cloudflare-rules-config
        $this->publishes([
            __DIR__.'/../config/migrate-cloudflare-rules.php' => config_path('migrate-cloudflare-rules.php'),
        ], 'migrate-cloudflare-rules-config');

        $this->commands([
            MigrateCloudflareRules::class,
        ]);
    }
}

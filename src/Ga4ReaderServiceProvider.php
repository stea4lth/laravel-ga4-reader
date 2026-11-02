<?php

declare(strict_types=1);

namespace Stea4lth\Ga4Reader;

use Illuminate\Support\ServiceProvider;

class Ga4ReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ga4-reader.php', 'ga4-reader');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ga4-reader.php' => config_path('ga4-reader.php'),
            ], 'ga4-reader-config');
        }
    }
}

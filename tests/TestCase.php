<?php

declare(strict_types=1);

namespace Stea4lth\Ga4Reader\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Stea4lth\Ga4Reader\Ga4ReaderServiceProvider;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [Ga4ReaderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        GoogleAnalyticsTraffic::resolveCacheKeyUsing(null);

        parent::tearDown();
    }
}

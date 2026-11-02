<?php

declare(strict_types=1);

use Stea4lth\Ga4Reader\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Configured enough to try (is_file() passes) but the credentials never
 * parse, so every GA call throws without reaching the network.
 */
function unusableGaCredentials(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ga-creds');
    file_put_contents($path, '{"not":"a service account"}');

    config()->set('ga4-reader.property_id', '123456');
    config()->set('ga4-reader.credentials', $path);

    return $path;
}

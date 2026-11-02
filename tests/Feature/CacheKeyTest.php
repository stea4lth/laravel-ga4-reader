<?php

declare(strict_types=1);

use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Illuminate\Support\Facades\Cache;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

/*
| The package never knows how an app versions its cache. The prefix comes from
| config; anything the prefix cannot express (a release, a tenant) plugs in as
| a resolver closure, and every key the reader writes goes through it.
*/

/** A reader whose GA calls answer with an empty report and are counted. */
function fakeGaReader(): GoogleAnalyticsTraffic
{
    config()->set('ga4-reader.property_id', '123456');
    config()->set('ga4-reader.credentials', __FILE__); // any readable file; GA is never reached

    return new class extends GoogleAnalyticsTraffic
    {
        public int $calls = 0;

        protected function runReport(RunReportRequest $request): RunReportResponse
        {
            $this->calls++;

            return new RunReportResponse;
        }
    };
}

it('caches the live payload under the configured prefix', function (): void {
    config()->set('ga4-reader.cache_prefix', 'site-a:');
    $ga = fakeGaReader();

    $traffic = $ga->traffic();

    expect($traffic)->toMatchArray(['sample' => false, 'visitors_30d' => 0])
        ->and(Cache::get('site-a:admin.ga.traffic'))->toBe($traffic)
        ->and(Cache::has('admin.ga.traffic'))->toBeFalse();
});

it('serves the second call from the cache', function (): void {
    $ga = fakeGaReader();

    $ga->report(7);
    $calls = $ga->calls;
    $ga->report(7);

    expect($calls)->toBeGreaterThan(0)
        ->and($ga->calls)->toBe($calls);
});

it('passes every key through a registered resolver, after the prefix', function (): void {
    config()->set('ga4-reader.cache_prefix', 'site-a:');
    GoogleAnalyticsTraffic::resolveCacheKeyUsing(fn (string $key): string => $key.'@release-1');
    $ga = fakeGaReader();

    $report = $ga->report(30);

    expect($ga->cacheKey('admin.ga.report.30'))->toBe('site-a:admin.ga.report.30@release-1')
        ->and(Cache::get('site-a:admin.ga.report.30@release-1'))->toBe($report)
        ->and(Cache::has('site-a:admin.ga.report.30'))->toBeFalse();
});

it('builds a fresh payload when the resolver moves to a new release', function (): void {
    // The negative case: what a release-scoped key protects against is the old
    // release's payload being served. A changed resolver must miss the cache.
    $release = 'release-1';
    GoogleAnalyticsTraffic::resolveCacheKeyUsing(function (string $key) use (&$release): string {
        return $key.'@'.$release;
    });
    $ga = fakeGaReader();

    $ga->traffic();
    $calls = $ga->calls;

    $release = 'release-2';
    $ga->traffic();

    expect($ga->calls)->toBe($calls * 2);
});

it('scopes the failure window through the resolver too', function (): void {
    config()->set('ga4-reader.cache_prefix', 'site-a:');
    GoogleAnalyticsTraffic::resolveCacheKeyUsing(fn (string $key): string => 'tenant-1/'.$key);
    $credentials = unusableGaCredentials();

    (new GoogleAnalyticsTraffic)->traffic();

    expect(Cache::get('tenant-1/site-a:admin.ga.traffic:failed'))->toBeTrue()
        ->and(Cache::has('site-a:admin.ga.traffic:failed'))->toBeFalse();

    unlink($credentials);
});

it('uses the bare key with no prefix and no resolver', function (): void {
    expect((new GoogleAnalyticsTraffic)->cacheKey('admin.ga.traffic'))->toBe('admin.ga.traffic');
});

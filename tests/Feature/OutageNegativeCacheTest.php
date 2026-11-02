<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

/*
| Cache::remember only stores successes, so a GA property that throws on every
| call (revoked service account, quota, bad creds) would fire report() on
| EVERY admin render. The error budget is shared, so the failure is
| negative-cached too.
*/

it('reports a persistent outage once per window, not once per page view', function (): void {
    Exceptions::fake();
    $credentials = unusableGaCredentials();

    $ga = new GoogleAnalyticsTraffic;

    expect($ga->traffic())->toBeNull()
        ->and($ga->traffic())->toBeNull()
        ->and($ga->traffic())->toBeNull();

    Exceptions::assertReportedCount(1);
    // The credentials failure, not a bug in the reader itself.
    Exceptions::assertReported(InvalidArgumentException::class);

    unlink($credentials);
});

it('reports on every call when the failure is not remembered', function (): void {
    // The negative case for the guard above: with no failure window, the
    // suppression is gone and each call reports again.
    Exceptions::fake();
    $credentials = unusableGaCredentials();
    config()->set('ga4-reader.failure_ttl_minutes', 0);

    $ga = new GoogleAnalyticsTraffic;

    $ga->traffic();
    $ga->traffic();
    $ga->traffic();

    Exceptions::assertReportedCount(3);

    unlink($credentials);
});

it('negative-caches each report range independently', function (): void {
    Exceptions::fake();
    $credentials = unusableGaCredentials();

    $ga = new GoogleAnalyticsTraffic;

    expect($ga->report(7))->toBeNull()
        ->and($ga->report(7))->toBeNull()
        ->and($ga->report(30))->toBeNull()
        ->and($ga->report(30))->toBeNull();

    // One per range, not one per call; the dashboard's own key is separate.
    Exceptions::assertReportedCount(2);

    unlink($credentials);
});

it('retries once the negative cache expires', function (): void {
    Exceptions::fake();
    $credentials = unusableGaCredentials();

    $ga = new GoogleAnalyticsTraffic;

    expect($ga->traffic())->toBeNull();
    Exceptions::assertReportedCount(1);

    // The suppression is a cache entry, not a permanent latch.
    Cache::flush();

    expect($ga->traffic())->toBeNull();
    Exceptions::assertReportedCount(2);

    unlink($credentials);
});

it('returns null without calling GA when the property is unset', function (): void {
    Exceptions::fake();
    config()->set('ga4-reader.property_id', '');

    $ga = new GoogleAnalyticsTraffic;

    expect($ga->configured())->toBeFalse()
        ->and($ga->traffic())->toBeNull()
        ->and($ga->report(30))->toBeNull();

    Exceptions::assertNothingReported();
});

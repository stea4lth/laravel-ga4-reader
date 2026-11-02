<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

/*
| The two bucketers are pure functions over a 'YYYYMMDD' => count map, so they
| are driven directly rather than through a GA client. Both windows END TODAY,
| INCLUSIVE — the daily bucketer always did, the weekly one silently dropped
| today while the request it is built from asks GA for data through 'today'.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-30 14:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts today in the newest weekly bucket', function (): void {
    $points = GoogleAnalyticsTraffic::bucketWeekly(['20260830' => 42]);

    expect(array_sum(array_column($points, 'count')))->toBe(42);
});

it('spans exactly eight seven-day weekly buckets ending today', function (): void {
    // One hit on every day of the window plus one the day before it: 56 counted,
    // the 57th-oldest outside the window and not counted.
    $daily = [];
    for ($i = 0; $i <= 56; $i++) {
        $daily[Carbon::now()->copy()->subDays($i)->format('Ymd')] = 1;
    }

    $points = GoogleAnalyticsTraffic::bucketWeekly($daily);

    // Count pins the shape (8 x 7, no gap or overlap); the labels pin WHERE the
    // window sits, which is what a shift by one day would move.
    expect($points)->toHaveCount(8)
        ->and(array_sum(array_column($points, 'count')))->toBe(56)
        ->and(array_column($points, 'count'))->each->toBe(7)
        ->and($points[0]['label'])->toBe('Jul 6')     // oldest bucket starts today-55
        ->and($points[7]['label'])->toBe('Aug 24');   // newest starts today-6, ends today
});

it('counts today in the newest daily bucket', function (): void {
    $points = GoogleAnalyticsTraffic::bucketDaily(['20260830' => 9], 7);

    expect(array_sum(array_column($points, 'count')))->toBe(9);
});

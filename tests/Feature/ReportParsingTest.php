<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;

/*
| The other suites answer GA with an empty response, so the loops that read
| rows never run. These answer with rows and pin every field to its source.
*/

beforeEach(fn () => Carbon::setTestNow('2026-11-17 12:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('reads report totals in the order visitors, sessions, page views', function (): void {
    $report = readerWithRows(['totals' => [[11, 22, 33]]])->report(30);

    expect($report['visitors'])->toBe(11)
        ->and($report['sessions'])->toBe(22)
        ->and($report['page_views'])->toBe(33);
});

it('reports zero totals when GA returns no totals row', function (): void {
    $report = readerWithRows([])->report(30);

    expect([$report['visitors'], $report['sessions'], $report['page_views']])->toBe([0, 0, 0]);
});

it('reads each ranked breakdown from its own dimension', function (): void {
    $report = readerWithRows([
        'pagePath' => [['/pricing', 40], ['/', 25]],
        'landingPage' => [['/blog', 9]],
        'sessionDefaultChannelGroup' => [['Organic Search', 30]],
        'city' => [['Chicago', 12]],
        'deviceCategory' => [['mobile', 7], ['desktop', 5]],
    ])->report(30);

    expect($report['top_pages'])->toBe([['label' => '/pricing', 'value' => 40], ['label' => '/', 'value' => 25]])
        ->and($report['landing_pages'])->toBe([['label' => '/blog', 'value' => 9]])
        ->and($report['sources'])->toBe([['label' => 'Organic Search', 'value' => 30]])
        ->and($report['cities'])->toBe([['label' => 'Chicago', 'value' => 12]])
        ->and($report['devices'])->toBe([['label' => 'mobile', 'value' => 7], ['label' => 'desktop', 'value' => 5]]);
});

it('labels an empty dimension value (not set)', function (): void {
    $report = readerWithRows(['city' => [['', 12], ['Boston', 5]]])->report(30);

    expect($report['cities'])->toBe([
        ['label' => '(not set)', 'value' => 12],
        ['label' => 'Boston', 'value' => 5],
    ]);
});

it('places each GA day in its own 7-day report bucket', function (): void {
    $report = readerWithRows(['date' => [['20261117', 4], ['20261111', 6], ['20261110', 9]]])->report(7);

    // Daily buckets for 7 days: Nov 11 ... Nov 17. Nov 10 is outside the window.
    expect($report['over_time'])->toHaveCount(7)
        ->and($report['over_time'][0])->toBe(['label' => 'Nov 11', 'count' => 6])
        ->and($report['over_time'][6])->toBe(['label' => 'Nov 17', 'count' => 4]);
});

it('asks GA for the window the report names', function (): void {
    $ga = readerWithRows([]);
    $ga->report(90);

    $starts = array_map(fn ($r) => $r->getDateRanges()[0]->getStartDate(), $ga->requests);

    expect($starts)->each->toBe('90daysAgo');
});

it('reads the panel totals in the order visitors, page views', function (): void {
    $traffic = readerWithRows(['totals' => [[101, 0, 202]]])->traffic();

    // The panel asks for two metrics, so only the first two values exist in a real response.
    expect($traffic['visitors_30d'])->toBe(101)
        ->and($traffic['page_views_30d'])->toBe(0);
});

it('reads the panel breakdowns and the weekly series', function (): void {
    $traffic = readerWithRows([
        'pagePath' => [['/', 3]],
        'sessionDefaultChannelGroup' => [['Direct', 2]],
        'city' => [['', 1]],
        'date' => [['20261117', 8], ['20261111', 5]],
    ])->traffic();

    expect($traffic['top_pages'])->toBe([['label' => '/', 'value' => 3]])
        ->and($traffic['sources'])->toBe([['label' => 'Direct', 'value' => 2]])
        ->and($traffic['cities'])->toBe([['label' => '(not set)', 'value' => 1]])
        ->and($traffic['over_time'])->toHaveCount(8)
        ->and($traffic['over_time'][7])->toBe(['label' => 'Nov 11', 'count' => 13]);
});

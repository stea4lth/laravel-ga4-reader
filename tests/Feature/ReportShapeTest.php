<?php

declare(strict_types=1);

use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;
use Stea4lth\Ga4Reader\SampleAnalytics;

/*
| An admin page renders whichever of the three it gets (live, sample, empty),
| so they must carry the same keys. Only the `sample` flag differs.
*/

it('gives the sample and the empty report the same keys', function (int $days): void {
    $sample = SampleAnalytics::report($days);
    $empty = GoogleAnalyticsTraffic::emptyReport($days);

    expect(array_keys($sample))->toBe(array_keys($empty))
        ->and($sample['sample'])->toBeTrue()
        ->and($empty['sample'])->toBeFalse()
        ->and($sample['over_time'])->toHaveCount(count($empty['over_time']));
})->with(GoogleAnalyticsTraffic::REPORT_RANGES);

it('gives the live report the same keys as the sample', function (): void {
    config()->set('ga4-reader.property_id', '123456');
    config()->set('ga4-reader.credentials', __FILE__); // any readable file; GA is never reached

    $ga = new class extends GoogleAnalyticsTraffic
    {
        protected function runReport(
            RunReportRequest $request,
        ): RunReportResponse {
            return new RunReportResponse;
        }
    };

    expect(array_keys($ga->report(30)))->toBe(array_keys(SampleAnalytics::report(30)));
});

<?php

declare(strict_types=1);

use Google\Analytics\Data\V1beta\DimensionValue;
use Google\Analytics\Data\V1beta\MetricValue;
use Google\Analytics\Data\V1beta\Row;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;
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

/**
 * A reader whose GA calls answer from canned rows, picked by the request's
 * first dimension: ['city' => [['Chicago', 12], ...]]. A request with no
 * dimension answers from 'totals' => [[visitors, sessions, views]]. Anything
 * not listed answers with no rows. Every request it sees is kept in
 * $requests so a test can assert on what was asked.
 *
 * @param  array<string, list<list<int|string>>>  $byDimension
 */
function readerWithRows(array $byDimension): GoogleAnalyticsTraffic
{
    config()->set('ga4-reader.property_id', '123456');
    config()->set('ga4-reader.credentials', __FILE__); // any readable file; GA is never reached

    return new class($byDimension) extends GoogleAnalyticsTraffic
    {
        /** @var list<RunReportRequest> */
        public array $requests = [];

        /** @param array<string, list<list<int|string>>> $byDimension */
        public function __construct(private readonly array $byDimension)
        {
            parent::__construct();
        }

        protected function runReport(RunReportRequest $request): RunReportResponse
        {
            $this->requests[] = $request;

            $dimensions = iterator_to_array($request->getDimensions());
            $name = $dimensions === [] ? 'totals' : $dimensions[0]->getName();

            return (new RunReportResponse)->setRows(array_map(
                fn (array $r): Row => $name === 'totals'
                    ? (new Row)->setMetricValues(array_map(fn ($v) => (new MetricValue)->setValue((string) $v), array_slice($r, 0, count($request->getMetrics()))))
                    : (new Row)
                        ->setDimensionValues([(new DimensionValue)->setValue((string) $r[0])])
                        ->setMetricValues([(new MetricValue)->setValue((string) $r[1])]),
                $this->byDimension[$name] ?? [],
            ));
        }
    };
}

<?php

declare(strict_types=1);

namespace Stea4lth\Ga4Reader;

use Closure;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * GA4 Data API traffic for an admin dashboard. Returns the SAME array shape
 * as SampleAnalytics so the page renders identically live or sample, and
 * returns null on ANY API failure so the admin never 500s on an analytics
 * hiccup: the caller falls back to the sample (or empty) panel.
 */
class GoogleAnalyticsTraffic
{
    private const CACHE_KEY = 'admin.ga.traffic';

    /** Per-range report cache, suffixed with the window in days. */
    private const CACHE_KEY_REPORT = 'admin.ga.report.';

    /** Suffix of the negative-cache entry that remembers a failure. */
    private const FAILED_SUFFIX = ':failed';

    /** Date windows report() accepts, in days. */
    public const REPORT_RANGES = [7, 30, 90];

    /** @var (Closure(string): string)|null */
    private static ?Closure $cacheKeyResolver = null;

    private readonly string $propertyId;

    private readonly string $credentialsPath;

    private ?BetaAnalyticsDataClient $client = null;

    public function __construct()
    {
        $this->propertyId = (string) config('ga4-reader.property_id');
        $this->credentialsPath = (string) config('ga4-reader.credentials');
    }

    /**
     * Map every cache key the reader writes, after the configured prefix is
     * applied: scope it to a release, a tenant, anything the prefix cannot
     * express. Pass null to remove it. Register it once, in a service
     * provider's boot().
     *
     * @param  (callable(string): string)|null  $resolver
     */
    public static function resolveCacheKeyUsing(?callable $resolver): void
    {
        self::$cacheKeyResolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /** The cache key actually used for $key: prefix, then the resolver. */
    public function cacheKey(string $key): string
    {
        $key = (string) config('ga4-reader.cache_prefix', '').$key;

        return self::$cacheKeyResolver === null ? $key : (self::$cacheKeyResolver)($key);
    }

    /** False in dev/test and on staging without creds: the caller keeps the sample panel. */
    public function configured(): bool
    {
        return $this->propertyId !== '' && is_file($this->credentialsPath);
    }

    /**
     * Cached live traffic, or null on failure so the caller can fall back.
     *
     * @return array{sample: false, visitors_30d: int, page_views_30d: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>}|null
     */
    public function traffic(): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        return $this->cached(self::CACHE_KEY, fn () => $this->fetch());
    }

    /**
     * The fuller report for a dedicated analytics page. Cached per range; null
     * on failure/unconfigured so the caller shows the sample.
     *
     * @return array{sample: false, days: int, visitors: int, sessions: int, page_views: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, landing_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>, devices: list<array{label: string, value: int}>}|null
     */
    public function report(int $days = 30): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $days = $this->normalizeDays($days);

        return $this->cached(self::CACHE_KEY_REPORT.$days, fn () => $this->fetchReport($days));
    }

    /**
     * Cache the payload on success and the FAILURE on failure, so a persistent
     * GA outage costs one report per key per window rather than one per render.
     * Null either way: the caller falls back to the sample panel.
     *
     * Cache::remember only stores successes, so without the failure entry a GA
     * property that throws on every call reported once per admin page view.
     *
     * @param  callable(): array<string, mixed>  $fetch
     * @return array<string, mixed>|null
     */
    private function cached(string $key, callable $fetch): ?array
    {
        $failedKey = $this->cacheKey($key.self::FAILED_SUFFIX);

        if (Cache::get($failedKey) === true) {
            return null;
        }

        try {
            return Cache::remember(
                $this->cacheKey($key),
                now()->addMinutes((int) config('ga4-reader.cache_ttl_minutes', 30)),
                $fetch,
            );
        } catch (Throwable $e) {
            Cache::put($failedKey, true, now()->addMinutes((int) config('ga4-reader.failure_ttl_minutes', 5)));
            report($e);

            return null;
        }
    }

    /** Clamp an arbitrary day count to a supported window (default 30). */
    public function normalizeDays(int $days): int
    {
        return in_array($days, self::REPORT_RANGES, true) ? $days : 30;
    }

    /**
     * For a deployed site whose GA is not reporting yet. `sample => false`
     * keeps the "sample" badge off: these zeros are honest, not placeholder.
     *
     * @return array{sample: false, days: int, visitors: int, sessions: int, page_views: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, landing_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>, devices: list<array{label: string, value: int}>}
     */
    public static function emptyReport(int $days): array
    {
        return [
            'sample' => false,
            'days' => $days,
            'visitors' => 0,
            'sessions' => 0,
            'page_views' => 0,
            'over_time' => self::bucketDaily([], $days),
            'top_pages' => [],
            'landing_pages' => [],
            'sources' => [],
            'cities' => [],
            'devices' => [],
        ];
    }

    /**
     * Every GA call goes through here. Overridable so a test can answer with a
     * canned response instead of reaching Google.
     */
    protected function runReport(RunReportRequest $request): RunReportResponse
    {
        return $this->client()->runReport($request);
    }

    private function client(): BetaAnalyticsDataClient
    {
        return $this->client ??= new BetaAnalyticsDataClient([
            'credentials' => $this->credentialsPath,
            'transport' => 'rest', // no grpc extension needed
        ]);
    }

    /**
     * @return array{sample: false, days: int, visitors: int, sessions: int, page_views: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, landing_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>, devices: list<array{label: string, value: int}>}
     */
    private function fetchReport(int $days): array
    {
        $start = "{$days}daysAgo";

        [$visitors, $sessions, $pageViews] = $this->reportTotals($start);

        return [
            'sample' => false,
            'days' => $days,
            'visitors' => $visitors,
            'sessions' => $sessions,
            'page_views' => $pageViews,
            'over_time' => $this->overTimeRange($days),
            'top_pages' => $this->topDimension('pagePath', 'screenPageViews', 6, $start),
            'landing_pages' => $this->topDimension('landingPage', 'sessions', 6, $start),
            'sources' => $this->topDimension('sessionDefaultChannelGroup', 'sessions', 6, $start),
            'cities' => $this->topDimension('city', 'activeUsers', 6, $start),
            'devices' => $this->topDimension('deviceCategory', 'activeUsers', 4, $start),
        ];
    }

    /**
     * A report with no dimensions returns one totals row (none when no data).
     *
     * @return array{0: int, 1: int, 2: int} [visitors, sessions, pageViews]
     */
    private function reportTotals(string $start): array
    {
        $response = $this->runReport(
            (new RunReportRequest)
                ->setProperty($this->property())
                ->setDateRanges([(new DateRange)->setStartDate($start)->setEndDate('today')])
                ->setMetrics([
                    (new Metric)->setName('activeUsers'),
                    (new Metric)->setName('sessions'),
                    (new Metric)->setName('screenPageViews'),
                ])
        );

        foreach ($response->getRows() as $row) {
            $v = $row->getMetricValues();

            return [(int) $v[0]->getValue(), (int) $v[1]->getValue(), (int) $v[2]->getValue()];
        }

        return [0, 0, 0];
    }

    /**
     * Bucketed (daily up to 7d, 3-day up to 30d, weekly otherwise) so the line
     * chart stays legible at 90 days.
     *
     * @return list<array{label: string, count: int}>
     */
    private function overTimeRange(int $days): array
    {
        $response = $this->runReport(
            (new RunReportRequest)
                ->setProperty($this->property())
                ->setDateRanges([(new DateRange)->setStartDate("{$days}daysAgo")->setEndDate('today')])
                ->setDimensions([(new Dimension)->setName('date')])
                ->setMetrics([(new Metric)->setName('activeUsers')])
        );

        // 'YYYYMMDD' => visitors
        $daily = [];
        foreach ($response->getRows() as $row) {
            $daily[$row->getDimensionValues()[0]->getValue()] = (int) $row->getMetricValues()[0]->getValue();
        }

        return self::bucketDaily($daily, $days);
    }

    /**
     * Shared with the sample fallback so live and sample charts read
     * identically. Window is the last $days, today inclusive.
     *
     * @param  array<string, int>  $daily
     * @return list<array{label: string, count: int}>
     */
    public static function bucketDaily(array $daily, int $days): array
    {
        $bucketDays = $days <= 7 ? 1 : ($days <= 30 ? 3 : 7);
        $now = Carbon::now();
        $start = $now->copy()->subDays($days - 1)->startOfDay();

        $points = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($now);) {
            $chunkEnd = $cursor->copy()->addDays($bucketDays);

            $count = 0;
            for ($day = $cursor->copy(); $day->lessThan($chunkEnd) && $day->lessThanOrEqualTo($now); $day->addDay()) {
                $count += $daily[$day->format('Ymd')] ?? 0;
            }

            $points[] = ['label' => $cursor->format('M j'), 'count' => $count];
            $cursor = $chunkEnd;
        }

        return $points;
    }

    /**
     * @return array{sample: false, visitors_30d: int, page_views_30d: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>}
     */
    private function fetch(): array
    {
        [$visitors, $pageViews] = $this->totals();

        return [
            'sample' => false,
            'visitors_30d' => $visitors,
            'page_views_30d' => $pageViews,
            'over_time' => $this->overTime(),
            'top_pages' => $this->topDimension('pagePath', 'screenPageViews'),
            'sources' => $this->topDimension('sessionDefaultChannelGroup', 'sessions'),
            'cities' => $this->topDimension('city', 'activeUsers'),
        ];
    }

    private function property(): string
    {
        return "properties/{$this->propertyId}";
    }

    /**
     * A report with no dimensions returns one totals row (none when no data).
     *
     * @return array{0: int, 1: int} [visitors, pageViews]
     */
    private function totals(): array
    {
        $response = $this->runReport(
            (new RunReportRequest)
                ->setProperty($this->property())
                ->setDateRanges([(new DateRange)->setStartDate('30daysAgo')->setEndDate('today')])
                ->setMetrics([
                    (new Metric)->setName('activeUsers'),
                    (new Metric)->setName('screenPageViews'),
                ])
        );

        foreach ($response->getRows() as $row) {
            $values = $row->getMetricValues();

            return [(int) $values[0]->getValue(), (int) $values[1]->getValue()];
        }

        return [0, 0];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function topDimension(string $dimension, string $metric, int $limit = 5, string $startDate = '30daysAgo'): array
    {
        $response = $this->runReport(
            (new RunReportRequest)
                ->setProperty($this->property())
                ->setDateRanges([(new DateRange)->setStartDate($startDate)->setEndDate('today')])
                ->setDimensions([(new Dimension)->setName($dimension)])
                ->setMetrics([(new Metric)->setName($metric)])
                ->setOrderBys([
                    (new OrderBy)
                        ->setMetric((new MetricOrderBy)->setMetricName($metric))
                        ->setDesc(true),
                ])
                ->setLimit($limit)
        );

        $rows = [];
        foreach ($response->getRows() as $row) {
            $label = $row->getDimensionValues()[0]->getValue();
            $rows[] = [
                'label' => $label === '' ? '(not set)' : $label,
                'value' => (int) $row->getMetricValues()[0]->getValue(),
            ];
        }

        return $rows;
    }

    /**
     * Eight rolling 7-day buckets, oldest to newest: the panel's mini trend.
     *
     * @return list<array{label: string, count: int}>
     */
    private function overTime(): array
    {
        $response = $this->runReport(
            (new RunReportRequest)
                ->setProperty($this->property())
                // The window must match bucketWeekly()'s arithmetic exactly or
                // the leftmost point under-counts: 8 buckets x 7 calendar days
                // ending TODAY reach back to today-55.
                ->setDateRanges([(new DateRange)->setStartDate('55daysAgo')->setEndDate('today')])
                ->setDimensions([(new Dimension)->setName('date')])
                ->setMetrics([(new Metric)->setName('screenPageViews')])
        );

        // 'YYYYMMDD' => page views
        $daily = [];
        foreach ($response->getRows() as $row) {
            $daily[$row->getDimensionValues()[0]->getValue()] = (int) $row->getMetricValues()[0]->getValue();
        }

        return self::bucketWeekly($daily);
    }

    /**
     * Eight rolling 7-day buckets, oldest to newest, the newest ending TODAY
     * INCLUSIVE: the same window convention as {@see bucketDaily()}.
     *
     * @param  array<string, int>  $daily  'YYYYMMDD' => count
     * @return list<array{label: string, count: int}>
     */
    public static function bucketWeekly(array $daily): array
    {
        $today = Carbon::now()->startOfDay();

        return collect(range(7, 0))
            ->map(function (int $i) use ($daily, $today): array {
                // $end is the last calendar DAY of the bucket, not a boundary.
                $end = $today->copy()->subWeeks($i);
                $start = $end->copy()->subDays(6);

                $count = 0;
                for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
                    $count += $daily[$day->format('Ymd')] ?? 0;
                }

                return ['label' => $start->format('M j'), 'count' => $count];
            })
            ->all();
    }
}

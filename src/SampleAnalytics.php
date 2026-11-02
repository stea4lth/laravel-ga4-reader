<?php

declare(strict_types=1);

namespace Stea4lth\Ga4Reader;

use Illuminate\Support\Carbon;

/**
 * Deterministic placeholder figures so an admin looks alive before real
 * traffic exists. Everything here is made up, but stable: the same window
 * always renders the same numbers.
 *
 * **Gate every use behind an app-side LOCAL DEMO flag.** A deployed site must
 * never present fabricated figures: with the flag off it shows
 * {@see GoogleAnalyticsTraffic::emptyReport()} instead. Typical consumers:
 *
 *  - a "Visitors · 30d" KPI tile, used only when GA4 isn't configured
 *    ({@see GoogleAnalyticsTraffic::traffic()} is the live source);
 *  - an analytics page: report() is the fallback when
 *    {@see GoogleAnalyticsTraffic::report()} returns null (unconfigured/down),
 *    and mirrors its shape exactly so the page renders identically;
 *  - an orders/revenue trend chart's EMPTY fallback (dailySales()).
 */
class SampleAnalytics
{
    /**
     * Per-day visitor series with a weekday rhythm, gentle upward drift, and
     * stable pseudo-noise.
     *
     * @return list<array{date: string, label: string, value: int}>
     */
    public static function dailyVisitors(int $days): array
    {
        // Weekend dip, mid-week peak (index 0 = Sunday).
        $weekly = [46, 63, 74, 81, 85, 70, 50];

        return self::buildSeries($days, function (Carbon $day, int $i) use ($weekly): int {
            $base = $weekly[(int) $day->dayOfWeek];
            $drift = (int) round($i * 0.7);
            $wobble = (($i * 7919) % 17) - 8;

            return max(0, $base + $drift + $wobble);
        });
    }

    /**
     * Per-day orders + revenue (cents), the trend chart's empty-state fallback.
     * Revenue is orders × a stable average order value, so the two series stay
     * internally consistent.
     *
     * @return list<array{date: string, label: string, orders: int, revenue: int}>
     */
    public static function dailySales(int $days): array
    {
        $visitors = self::dailyVisitors($days);

        return array_map(function (array $row, int $i): array {
            // ~3-5% of visitors convert, nudged by stable per-day noise.
            $orders = (int) max(0, round($row['value'] * 0.04) + ((($i * 5701) % 5) - 2));
            $aov = 3800 + (($i * 3313) % 2600); // $38–$64 average order value, in cents.

            return [
                'date' => $row['date'],
                'label' => $row['label'],
                'orders' => $orders,
                'revenue' => $orders * $aov,
            ];
        }, $visitors, array_keys($visitors));
    }

    /**
     * Full sample analytics report for an analytics page. Mirrors
     * {@see GoogleAnalyticsTraffic::report()}'s shape exactly (flagged
     * `sample => true`) so the page renders identically live or sample. The
     * ranked lists are scaled to the window so the range picker visibly moves
     * the numbers and nobody mistakes them for live data.
     *
     * @return array{sample: true, days: int, visitors: int, sessions: int, page_views: int, over_time: list<array{label: string, count: int}>, top_pages: list<array{label: string, value: int}>, landing_pages: list<array{label: string, value: int}>, sources: list<array{label: string, value: int}>, cities: list<array{label: string, value: int}>, devices: list<array{label: string, value: int}>}
     */
    public static function report(int $days): array
    {
        $daily = self::dailyVisitors($days);
        $visitors = (int) array_sum(array_column($daily, 'value'));

        // 'Ymd' => visitors, so the same helper the live report uses can
        // bucket it and both trend charts read identically.
        $byDate = [];
        foreach ($daily as $row) {
            $byDate[str_replace('-', '', $row['date'])] = $row['value'];
        }

        return [
            'sample' => true,
            'days' => $days,
            'visitors' => $visitors,
            'sessions' => (int) round($visitors * 1.18),
            'page_views' => (int) round($visitors * 2.6),
            'over_time' => GoogleAnalyticsTraffic::bucketDaily($byDate, $days),
            'top_pages' => self::scale([
                ['label' => '/', 'value' => 1180],
                ['label' => '/shop', 'value' => 820],
                ['label' => '/product/sample-product', 'value' => 396],
                ['label' => '/product/another-product', 'value' => 274],
                ['label' => '/shop/category-one', 'value' => 210],
                ['label' => '/about', 'value' => 168],
            ], $days, 'value'),
            'landing_pages' => self::scale([
                ['label' => '/', 'value' => 742],
                ['label' => '/shop', 'value' => 286],
                ['label' => '/product/sample-product', 'value' => 168],
                ['label' => '/shop/category-two', 'value' => 112],
                ['label' => '/contact', 'value' => 78],
            ], $days, 'value'),
            'sources' => self::scale([
                ['label' => 'Organic Search', 'value' => 612],
                ['label' => 'Direct', 'value' => 421],
                ['label' => 'Organic Social', 'value' => 238],
                ['label' => 'Referral', 'value' => 156],
                ['label' => 'Paid Search', 'value' => 64],
            ], $days, 'value'),
            'cities' => self::scale([
                ['label' => 'New York', 'value' => 184],
                ['label' => 'Los Angeles', 'value' => 142],
                ['label' => 'Chicago', 'value' => 98],
                ['label' => 'Houston', 'value' => 86],
                ['label' => 'Phoenix', 'value' => 71],
            ], $days, 'value'),
            'devices' => self::scale([
                ['label' => 'mobile', 'value' => 712],
                ['label' => 'desktop', 'value' => 398],
                ['label' => 'tablet', 'value' => 84],
            ], $days, 'value'),
        ];
    }

    /**
     * Build a deterministic per-day series from a value generator.
     *
     * @param  callable(Carbon, int): int  $value
     * @return list<array{date: string, label: string, value: int}>
     */
    private static function buildSeries(int $days, callable $value): array
    {
        $start = Carbon::today()->subDays($days - 1);
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('M j'),
                'value' => $value($day, $i),
            ];
        }

        return $series;
    }

    /**
     * Scale a 30-day baseline row set to the requested window.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function scale(array $rows, int $days, string $key): array
    {
        $factor = $days / 30;

        return array_map(function (array $row) use ($factor, $key): array {
            $row[$key] = (int) round($row[$key] * $factor);

            return $row;
        }, $rows);
    }
}

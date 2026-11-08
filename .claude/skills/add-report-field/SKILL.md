---
name: add-report-field
description: Add a GA4 metric, dimension breakdown or new report method to this package, test first, keeping the live, sample and empty shapes in step. Use for "add bounce rate", "add a countries breakdown", "report on X", or any change to what report() or traffic() returns.
---

# Add a GA4 report field, test first

Every report this package returns has three producers: the live one (`GoogleAnalyticsTraffic::fetchReport()` or
`fetch()`), the sample (`SampleAnalytics::report()`) and the empty one (`GoogleAnalyticsTraffic::emptyReport()`).
A consumer's Blade view renders whichever it gets, so a key missing from one of them is a production error in
someone else's app. Work in this order.

## 1. Confirm the GA4 names

Use the exact API names from the GA4 Data API schema (for example `bounceRate`, `country`, `newUsers`), not the
names in the GA4 web UI. If unsure, check https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema
before writing code. A wrong name only fails at runtime against a real property, and the reader swallows it as
`null`, so no test here would catch it.

## 2. Write the failing tests

Add to the file for the behaviour, not a new file per change:

- **Shape**, in `tests/Feature/ReportShapeTest.php`: the existing key-equality tests already cover a new key once
  all three producers have it. Run them first and watch them fail when only one producer has it.
- **Parsing**, in a new `tests/Feature/ReportParsingTest.php` if it does not exist yet: answer `runReport()` with
  rows, so the parsing code actually runs. The existing tests only return an empty response, so the loops over
  `getRows()` never execute in them.

```php
use Google\Analytics\Data\V1beta\DimensionValue;
use Google\Analytics\Data\V1beta\MetricValue;
use Google\Analytics\Data\V1beta\Row;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

/**
 * A reader whose GA calls answer from canned rows, picked by the request's
 * first dimension: ['city' => [['Chicago', 12], ...]]. A request with no
 * dimension (the totals) answers from 'totals' => [[visitors, sessions, views]].
 * Anything not listed answers with no rows.
 */
function readerWithRows(array $byDimension): GoogleAnalyticsTraffic
{
    config()->set('ga4-reader.property_id', '123456');
    config()->set('ga4-reader.credentials', __FILE__); // any readable file; GA is never reached

    return new class($byDimension) extends GoogleAnalyticsTraffic
    {
        public function __construct(private array $byDimension)
        {
            parent::__construct();
        }

        protected function runReport(RunReportRequest $request): RunReportResponse
        {
            $dimensions = iterator_to_array($request->getDimensions());
            $name = $dimensions === [] ? 'totals' : $dimensions[0]->getName();

            return (new RunReportResponse)->setRows(array_map(
                fn (array $r): Row => $name === 'totals'
                    ? (new Row)->setMetricValues(array_map(fn ($v) => (new MetricValue)->setValue((string) $v), $r))
                    : (new Row)
                        ->setDimensionValues([(new DimensionValue)->setValue($r[0])])
                        ->setMetricValues([(new MetricValue)->setValue((string) $r[1])]),
                $this->byDimension[$name] ?? [],
            ));
        }
    };
}

it('labels an empty country as (not set)', function (): void {
    $report = readerWithRows(['country' => [['', 12], ['Canada', 5]]])->report(30);

    expect($report['countries'])->toBe([
        ['label' => '(not set)', 'value' => 12],
        ['label' => 'Canada', 'value' => 5],
    ]);
});
```

If the new code inspects the request (a filter, a different date range), capture `$request` inside the fake and
assert on `$request->getDimensions()` / `getDateRanges()` too.

Run only these files while working: `vendor/bin/pest tests/Feature/ReportShapeTest.php tests/Feature/ReportParsingTest.php`.

## 3. Implement

- Live: add the call in `fetchReport()` (or `fetch()` for the dashboard panel). Reuse `topDimension()` for a
  ranked breakdown; it already orders, limits and labels `(not set)`. Every GA request goes through `runReport()`.
- Sample: add stable, plausible fake values in `SampleAnalytics::report()`. Deterministic: no `rand()`, derive
  from the day index like the existing series.
- Empty: add the zero value (`0` or `[]`) in `emptyReport()`.
- Update the `@return array{...}` shape on all three methods. Keep key order identical: the shape test compares
  `array_keys()` in order.
- Never add a cache call outside `cached()`. A new report method gets its own key constant and goes through
  `cached()`, so the prefix, the resolver and the failure window apply to it.

## 4. Finish

- `composer test` and `composer lint` both clean.
- Update README "Array shapes" with the new key and its meaning.
- A new key is a backwards-compatible addition (minor version). Renaming or removing a key breaks consumers'
  views (major, or minor while below 1.0); say which in the pull request.
- Changing the shape changes what is cached. Mention in the pull request that apps should scope cache keys to a
  release (README "Release- or tenant-scoped cache keys"), or old cached arrays without the key are served for up
  to `cache_ttl_minutes`.

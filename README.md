# laravel-ga4-reader

Read website traffic from the Google Analytics 4 Data API in a Laravel app, for
an admin dashboard or a visitors count.

- **Cached.** Each report is cached for 30 minutes.
- **Fails quietly, reports once.** Any API failure returns `null` so the page
  never 500s; the failure itself is cached for 5 minutes, so a broken property
  (revoked service account, quota, bad key) is reported once per window, not
  once per page view.
- **Same shape live, sample or empty.** `SampleAnalytics::report()` and
  `GoogleAnalyticsTraffic::emptyReport()` return exactly the keys
  `GoogleAnalyticsTraffic::report()` does, so one view renders all three.
- **Your cache keys.** A configurable prefix, plus an optional resolver closure
  for release- or tenant-scoped keys.

Uses the REST transport, so the `grpc` extension is not needed.

## Requirements

PHP 8.3+, Laravel 12 or 13.

## Install

```bash
composer require stea4lth/laravel-ga4-reader
```

The service provider is auto-discovered.

### Until it is on Packagist

Install straight from the public GitHub repo. `no-api` makes Composer do a plain
git clone, so no GitHub token is needed and no API rate limit applies:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/stea4lth/laravel-ga4-reader", "no-api": true}
]
```

then `composer require stea4lth/laravel-ga4-reader:^0.1`.

## Configure

Set two environment variables:

| Variable | Meaning |
|---|---|
| `GOOGLE_ANALYTICS_PROPERTY_ID` | The numeric GA4 property id (without `properties/`). |
| `GOOGLE_ANALYTICS_CREDENTIALS` | Path to a service-account JSON key with Viewer access on the property. Default: `storage/app/google/ga-service-account.json`. |
| `GA4_READER_CACHE_PREFIX` | Optional. Prepended to every cache key. Default: empty. |

Unset property id, or a credentials path that is not a file, means
"unconfigured": the reader returns `null` and never calls Google.

Publish the config to change the cache and failure windows:

```bash
php artisan vendor:publish --tag=ga4-reader-config
```

```php
// config/ga4-reader.php
'cache_ttl_minutes' => 30,   // successful reports
'failure_ttl_minutes' => 5,  // how long a failure is remembered
```

### Release- or tenant-scoped cache keys

Register a resolver in a service provider's `boot()`. It receives every key the
reader writes, after the prefix, and returns the key to use:

```php
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;

GoogleAnalyticsTraffic::resolveCacheKeyUsing(
    fn (string $key): string => $key.'@'.substr((string) config('sentry.release'), 0, 12)
);
```

A release-scoped key means a release that reshapes a report never serves the
previous release's cached shape. The failure entry goes through the resolver
too, so a tenant's outage never silences another tenant.

## Use

```php
use Stea4lth\Ga4Reader\GoogleAnalyticsTraffic;
use Stea4lth\Ga4Reader\SampleAnalytics;

public function index(Request $request, GoogleAnalyticsTraffic $ga)
{
    $days = $ga->normalizeDays((int) $request->query('days', 30)); // 7, 30 or 90

    $report = $ga->report($days)
        ?? (config('analytics.sample')          // your own local-demo flag
            ? SampleAnalytics::report($days)
            : GoogleAnalyticsTraffic::emptyReport($days));

    return view('admin.analytics', [
        'report' => $report,
        'ranges' => GoogleAnalyticsTraffic::REPORT_RANGES,
    ]);
}
```

Gate `SampleAnalytics` behind a local-demo flag of your own: a deployed site
should show the honest empty report, never made-up numbers.

## Array shapes

`report(int $days = 30)`, `SampleAnalytics::report($days)` and
`emptyReport($days)`:

```php
[
    'sample' => bool,          // true only from SampleAnalytics
    'days' => int,             // 7, 30 or 90
    'visitors' => int,         // activeUsers
    'sessions' => int,
    'page_views' => int,       // screenPageViews
    'over_time' => [['label' => 'Aug 24', 'count' => int], ...], // daily ≤7d, 3-day ≤30d, weekly
    'top_pages' => [['label' => '/path', 'value' => int], ...],
    'landing_pages' => [['label' => '/path', 'value' => int], ...],
    'sources' => [['label' => 'Organic Search', 'value' => int], ...],
    'cities' => [['label' => 'Chicago', 'value' => int], ...],
    'devices' => [['label' => 'mobile', 'value' => int], ...],
]
```

`traffic()`, the 30-day dashboard panel:

```php
[
    'sample' => false,
    'visitors_30d' => int,
    'page_views_30d' => int,
    'over_time' => [['label' => 'Jul 6', 'count' => int], ...], // 8 weekly buckets ending today
    'top_pages' => [['label' => '/path', 'value' => int], ...],
    'sources' => [['label' => 'Direct', 'value' => int], ...],
    'cities' => [['label' => 'Chicago', 'value' => int], ...],
]
```

Both return `null` when unconfigured or when GA fails. An empty dimension value
is labelled `(not set)`.

`SampleAnalytics` also has `dailyVisitors($days)` and `dailySales($days)`
(orders and revenue in cents) for a KPI tile or a trend chart's empty state.

## Tests

```bash
composer install
vendor/bin/pest
```

The tests never reach Google: failures come from an unparseable key file, and
successes from a reader whose `runReport()` returns a canned response.

## License

MIT. See [LICENSE](LICENSE).

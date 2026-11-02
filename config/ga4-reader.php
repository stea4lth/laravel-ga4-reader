<?php

declare(strict_types=1);

return [

    /*
    | The GA4 property to read (the numeric id, without the "properties/"
    | prefix). Empty means unconfigured: the reader returns null and the app
    | shows its sample or empty panel.
    */
    'property_id' => env('GOOGLE_ANALYTICS_PROPERTY_ID'),

    /*
    | Path to the service-account JSON key that has Viewer access on the
    | property. A path that is not a readable file also means unconfigured.
    */
    'credentials' => env('GOOGLE_ANALYTICS_CREDENTIALS', storage_path('app/google/ga-service-account.json')),

    /*
    | Prepended to every cache key the reader writes. An app can also register
    | a resolver closure for keying the prefix cannot express (a release or a
    | tenant): GoogleAnalyticsTraffic::resolveCacheKeyUsing(fn (string $key) => ...).
    */
    'cache_prefix' => env('GA4_READER_CACHE_PREFIX', ''),

    /* How long a successful report is cached. */
    'cache_ttl_minutes' => 30,

    /*
    | How long a FAILURE is remembered. A property that throws on every call
    | (revoked service account, quota, bad key) then reports once per key per
    | window instead of once per page view.
    */
    'failure_ttl_minutes' => 5,

];

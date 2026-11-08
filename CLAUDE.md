# laravel-ga4-reader

A Laravel package (not an app) that reads GA4 Data API reports. Three source files in `src/`, Pest tests in
`tests/`, run under Orchestra Testbench. Consumers install it with Composer, so every change ships to other
people's apps.

## Commands

- Install: `composer install` (no lock file is committed; that is normal for a library).
- Test: `composer test` (all suites, under a second). One file: `vendor/bin/pest tests/Feature/CacheKeyTest.php`.
- Lint: `composer lint` (Pint, Laravel preset). Fix: `composer format`. Both must be clean before a commit.
- Check the package: `composer validate --strict`, and `git archive HEAD | tar -t` to see what consumers download.

## Rules that hold on every change

- **Never reach Google in a test.** Subclass `GoogleAnalyticsTraffic` and override `runReport()` to return a canned
  `RunReportResponse`; for the failure path use `unusableGaCredentials()` from `tests/Pest.php`.
- **Every GA call goes through `runReport()`; every cache read or write goes through `cached()` / `cacheKey()`.**
  That is what makes the prefix, the resolver closure and the failure window apply everywhere.
- **Public methods return `null` on failure, never throw.** A dashboard must not 500 because GA did.
- **Live, sample and empty reports keep the same keys.** A new key goes in `fetchReport()`,
  `SampleAnalytics::report()` and `emptyReport()` in the same change, plus the PHPDoc array shape and the
  README "Array shapes" section. `tests/Feature/ReportShapeTest.php` enforces the keys.
- **PHP floor is 8.3** (`composer.json`), even when your local PHP is newer: no property hooks, asymmetric
  visibility, `array_find()` or `new Foo()->bar()` without parentheses.
- **Laravel 12 and 13 are both supported.** Use only `illuminate/*` APIs present in 12.
- **Changing `require` changes every consumer's dependency tree.** Add runtime dependencies only when asked;
  dev tools go in `require-dev`.
- Any test that writes a temp file deletes it even when an assertion fails (use `afterEach` or `try/finally`).

## Git and releases

- `main` is the default branch and the only long-lived one. Work on a branch, open a pull request to `main`.
- Stage explicit paths. Never commit `.env*` or a service-account JSON key.
- Tags `vX.Y.Z` are permanent once pushed: Composer users and Packagist resolve them. A bad release gets a new
  patch tag, never a moved or deleted one. Use the `release` skill.
- `.claude/hooks/guard.php` blocks force-pushes to `main` or a tag, `rm -rf` outside build output, staging
  secrets and edits under `vendor/`. If it blocks you, change the approach; do not work around it.

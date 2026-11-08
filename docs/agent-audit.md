# Claude Code agent audit: laravel-ga4-reader

Audited commit `1864c34` (tag `v0.1.0`) on 2026-11-08. Every claim below comes from a command run on that
checkout; the command and what it printed are given with each finding.

## Summary

This is a small, well-built package: three source files, 18 tests that run in 0.19 s and never touch the network,
and a README that explains its own design. A human maintainer has little trouble with it.

An agent has more. It starts every session knowing nothing, and nothing in the repository tells it the rules the
code depends on: every GA call must go through one overridable method, failures must return `null`, and three
separate producers must return identical array keys. It can install and test without guessing. It cannot lint
(there is no linter), cannot release (no written procedure), and nothing stops it from force-pushing `main`,
moving a published tag, or committing a Google service-account key.

| Can an agent... | Before | After the setup PR |
|---|---|---|
| Install | Yes: `composer install`, in the README | Yes |
| Test | Yes: `composer test`, 18 tests, 0.19 s | Yes, plus the safety hook's own tests |
| Lint | No: no linter installed; one test file already off-style | `composer lint` / `composer format` |
| Release | Guesswork: no changelog, no steps, no GitHub releases | `release` skill |
| Add a report field safely | Only by reading all of `src/` and `tests/` first | `CLAUDE.md` rules + `add-report-field` skill |
| Avoid irreversible mistakes | Nothing in place | PreToolUse hook + permission rules |

## Top 10 findings, by impact

### 1. No agent context at all

`ls CLAUDE.md AGENTS.md .claude .github CHANGELOG.md CONTRIBUTING.md` → none of them exist.

The invariants live only in code comments: `runReport()` is the test seam (`src/GoogleAnalyticsTraffic.php:178`),
every cache key must pass through `cacheKey()` (line 68) and `cached()` (line 123), and `fetchReport()`,
`SampleAnalytics::report()` and `emptyReport()` must return the same keys. An agent that adds a field to one
producer passes its own new test and breaks a consumer's view.

**Fix (in the PR):** a short `CLAUDE.md` with the commands and the rules that hold on every change.

### 2. The code that parses Google's response is never run by a test

`grep -rnE 'setRows|new Row|DimensionValue|MetricValue' tests` → no matches. Every fake `runReport()` in the
suite returns an empty `RunReportResponse`, so the loops that read rows never execute.
`pest --coverage` (PCOV) reports `GoogleAnalyticsTraffic` at 93.4%, and the uncovered lines are exactly the
parsing: `src/GoogleAnalyticsTraffic.php:234-236` (report totals), `261` (daily series), `337-339` (panel
totals), `366-370` (every ranked breakdown, including the `(not set)` label) and `397` (weekly series). A 91%
total hides it: the lines no test covers are the ones that touch real data.

To confirm, we swapped `$v[1]` and `$v[2]` on line 236 (sessions and page views) and ran the two test files
that call `report()` (`ReportShapeTest`, `CacheKeyTest`): 10 passed, 28 assertions. The bug went unnoticed;
the change was then reverted.

**Fix:** a `readerWithRows()` test helper that answers each request with rows chosen by its dimension. It is
written out in `.claude/skills/add-report-field/SKILL.md`; two example tests built on it were run against the
current code and passed (2 tests, 7 assertions). Recommended next step: add `tests/Feature/ReportParsingTest.php`
using it for every field.

### 3. Supported versions are declared, not tested

`composer.json` promises PHP `^8.3` and Laravel `^12.0|^13.0`. What the suite actually ran on:
`php -v` → PHP 8.4.26; `composer show` → laravel/framework 13.40.0, orchestra/testbench 11.3.0.
`composer update --prefer-lowest --prefer-stable --dry-run` resolves to laravel/framework 12.0.1, testbench
10.0.0, pest 3.8.0, a set no one has run. There is no CI (`.github` does not exist).

An agent on a PHP 8.4 machine can write 8.4-only syntax (property hooks, `new Foo()->bar()`) and every local
test passes; the first PHP 8.3 user gets a parse error.

**Fix:** `CLAUDE.md` now names the floor and the 8.4 features to avoid. Recommended next step: a CI matrix of
PHP 8.3 + `--prefer-lowest` and PHP 8.4 + latest.

### 4. Google service-account keys are not git-ignored

`git check-ignore` on `ga-service-account.json`, `storage/app/google/ga-service-account.json` and
`credentials.json` → all three NOT ignored. `.env` is ignored. The second path is the package's own default
(`config/ga4-reader.php:18`), exactly where someone trying the package locally drops a live key.

**Fix (in the PR):** `.gitignore` covers `*service-account*.json`, `credentials.json` and `/storage/`; the hook
refuses `git add` of such files and `git add --force`; `settings.json` denies the agent reading `.env*` and key
files.

### 5. Nothing stops an irreversible git operation

No `.claude/settings.json`, no hooks. In a published package the costly mistakes are a force-push to `main`
and a moved or deleted version tag: Composer users' lock files point at `v0.1.0`'s commit.

**Fix (in the PR):** `.claude/hooks/guard.php`, a PreToolUse hook that refuses force-pushes and deletes of `main`
or a `vX.Y.Z` tag, `rm -rf` outside build output, staging secrets, and edits under `vendor/`. It ships with
`tests/Tooling/GuardHookTest.php` (35 cases: each refusal next to an allowed neighbour). With the guard's
decision disabled on purpose, 23 of the 35 fail; restored, all pass. `git push`, `git tag`, `gh release` and
`composer require/update/remove` are set to ask first.

### 6. No lint or format tool

`ls vendor/bin | grep -iE 'pint|phpstan|psalm|php-cs|rector'` → none. After installing Laravel Pint,
`pint --test` failed on `tests/Feature/ReportShapeTest.php` (4 rules: imports, braces, class definition).

**Fix (in the PR):** `laravel/pint` in `require-dev`, `composer lint` and `composer format` scripts, that file
formatted (imports only, no behaviour change). `composer lint` now passes. Recommended next step: PHPStan
(Larastan) at a low level, since the array-shape PHPDoc is already written.

### 7. No release procedure

`git tag` → `v0.1.0`. `gh release list` → 0 releases. No `CHANGELOG.md`. Packagist returns HTTP 404 for the
package. The README tells users `composer require stea4lth/laravel-ga4-reader:^0.1`, and under Composer's rules
`^0.1` will never accept `0.2.0`, so the README must change on every minor release before 1.0. Nothing written
down says so.

**Fix (in the PR):** a `release` skill: version choice rules for 0.x and 1.x, the four gates, changelog and
README through a pull request, then an annotated tag and a GitHub release, with the maintainer's yes before the tag.

### 8. Agent files would ship inside the package

`git archive HEAD | tar -t` → `LICENSE`, `README.md`, `composer.json`, `config/`, `src/`. Good today, but
`.gitattributes` only excludes `tests` and `phpunit.xml.dist`. Adding `CLAUDE.md`, `.claude/` or `docs/` without
changing it puts them in every consumer's `vendor/`, and a consumer's agent would then read this package's rules
as if they were its own.

**Fix (in the PR):** `export-ignore` for `/.claude`, `/CLAUDE.md` and `/docs`. After the change the archive
listing is unchanged from the one above.

### 9. The Google client is pinned four minor versions back

`composer outdated --direct` → `google/analytics-data 0.24.0 ~ 0.29.0`. Below 1.0, `^0.24.0` means 0.24.x only, so
apps using this package cannot take Google's fixes even if they ask for them.
`composer why-not google/analytics-data 0.29.0` → blocked by this package's `^0.24.0`.

**Fix (recommended):** widen to `^0.24.0|^0.29.0` (or whichever versions the suite passes on) after adding the
parsing tests from finding 2, since a client upgrade is exactly what those tests would catch.

### 10. Twin methods an agent will update one of

`totals()` (line 324) and `reportTotals()` (line 220) build the same request with different metrics;
`overTime()` (line 381) and `overTimeRange()` (line 248) do the same for the series. A fix applied to one, such
as a date-range change, silently misses the other. Smaller in the same spirit: `tests/Pest.php:15` creates a
temp file that each test deletes on its last line, so a failing assertion leaks it.

**Fix (recommended):** fold each pair into one method with parameters; delete the temp file in `afterEach`.
`CLAUDE.md` already asks new tests to clean up that way.

## Test speed

Whole suite: 18 tests, 61 assertions, 0.19 s. An agent can run all of it after every edit, which is the best
case; nothing to fix. After the setup PR: 53 tests, 121 assertions, 1.29 s with coverage on. The hook tests add about a second,
almost all of it starting 35 PHP processes.

## What the setup PR adds, and why

| File | Why |
|---|---|
| `CLAUDE.md` | The commands and the rules that must hold on every change (finding 1, 3). Short, so it costs little context each session. |
| `.claude/settings.json` | Allows the everyday commands without prompts, asks before push, tag, release and dependency changes, denies reading secrets, and registers the hook. |
| `.claude/hooks/guard.php` | Refuses the irreversible operations (findings 4, 5). Plain PHP, so it needs nothing beyond what the package already requires. |
| `tests/Tooling/GuardHookTest.php` | Proves the hook blocks what it should and allows its neighbours; runs in `composer test`. |
| `.claude/skills/release/SKILL.md` | The release procedure (finding 7). |
| `.claude/skills/add-report-field/SKILL.md` | The test-first way to change a report, with a verified canned-rows helper (findings 1, 2). |
| `composer.json`, `ReportShapeTest.php` | Pint, `lint` and `format` scripts, one file formatted (finding 6). |
| `.gitignore`, `.gitattributes` | Key files ignored (finding 4); agent files kept out of the package (finding 8). |
| `phpunit.xml.dist` | A `Tooling` test suite for the hook test. |

No file under `src/` or `config/` changes, so the package behaves exactly as before.

## Not done here

Findings 2, 3, 9 and 10 are code and CI changes for their own pull requests, each with its tests first. They are
listed in the order to take them: parsing tests (2) make the client upgrade (9) and the refactor (10) safe, and
CI (3) keeps all of it honest.

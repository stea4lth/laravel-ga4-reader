---
name: release
description: Cut a new version of this package (vX.Y.Z tag, changelog, GitHub release) after the gates pass. Use for "release", "tag a version", "publish 0.2.0", "ship it".
---

# Release a version

A pushed tag is what Composer users install. It cannot be taken back safely: lock files and caches already point at
it. So everything is checked before the tag, and a mistake after it is fixed with a new patch version.

## 1. Start from an up-to-date main

```bash
git switch main && git fetch origin && git status -sb   # must say "## main...origin/main" with nothing else
git describe --tags --abbrev=0                          # the last version
git log --oneline "$(git describe --tags --abbrev=0)"..HEAD
```

No commits since the last tag: stop, there is nothing to release.

## 2. Pick the version

Read each commit since the last tag and sort it:

- **Breaking**: a report key renamed or removed, a public method's signature or return changed, a config key
  renamed, a PHP or Laravel version dropped.
- **Feature**: a new report key, method, config option, or a newly supported Laravel version.
- **Fix**: everything else that changes behaviour.

While the version is below 1.0, Composer's `^0.1` does not accept `0.2.0`, so: breaking or feature → bump the
minor (`0.1.3` → `0.2.0`); fix only → bump the patch. From 1.0 on: breaking → major, feature → minor, fix → patch.
State the version and the one-line reason to the maintainer and wait for a yes before tagging.

## 3. Gates (all must pass on the commit you will tag)

```bash
composer validate --strict
composer test
composer lint
git archive HEAD | tar -t      # only LICENSE, README.md, composer.json, config/, src/
```

Anything else in the archive (tests, `.claude/`, `docs/`, `CLAUDE.md`) means `.gitattributes` lost an
`export-ignore` line: fix that first, it ships to every consumer.

## 4. Changelog and README, through a pull request

- `CHANGELOG.md` (create it on first use, Keep a Changelog format): a `## [X.Y.Z] - YYYY-MM-DD` section with
  Added / Changed / Fixed / Removed lines written for someone upgrading. Breaking changes first, each with what the
  consumer must change.
- On a minor bump below 1.0, update the README install line `composer require stea4lth/laravel-ga4-reader:^0.N`.
- Commit on a `release/vX.Y.Z` branch, open a pull request to `main`, and wait for it to be merged.

## 5. Tag and publish (after the merge, and after the maintainer's yes)

```bash
git switch main && git pull --ff-only
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z --title "vX.Y.Z" --notes "<the CHANGELOG section>"
```

Tag only the merged commit on `main`, never a branch commit.

## 6. Verify

- `git ls-remote --tags origin vX.Y.Z` shows the tag.
- If the package is on Packagist, `https://repo.packagist.org/p2/stea4lth/laravel-ga4-reader.json` lists the
  version within a few minutes (a 404 means it is not registered there yet; consumers then install from the git
  repository as the README describes).

## Never

- Move, delete or force-push a tag that has been pushed (the safety hook blocks it). Release `X.Y.Z+1` instead.
- Tag with failing or skipped gates, or with uncommitted changes.

<?php

declare(strict_types=1);

/*
| PreToolUse safety hook for Claude Code in this repo.
|
| Claude Code pipes the pending tool call to stdin as JSON. Exit 2 blocks the
| call and shows stderr to the agent; exit 0 lets it through (the normal
| permission rules in .claude/settings.json still apply).
|
| It refuses the few things that are expensive to undo in a published package:
|   - force-pushing main/master or a version tag, deleting either remotely
|   - `rm` that is both recursive and forced, outside build output
|   - staging a secret (.env, a service-account key) or force-adding ignored files
|   - editing vendor/ (Composer overwrites it; the change silently vanishes)
|
| It is a seatbelt, not a sandbox: it reads the command text, so a determined
| obfuscation gets past it. Tests: tests/Tooling/GuardHookTest.php.
*/

const PROTECTED_BRANCHES = ['main', 'master'];

/** Paths `rm -rf` may target: build output and caches, all git-ignored. */
const RM_RF_ALLOWED = ['vendor', '.phpunit.cache', 'build', 'coverage'];

function guard_main(): int
{
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    if (! is_array($payload)) {
        return 0; // Not a tool call we understand: let the normal rules decide.
    }

    $reason = guard_check($payload);
    if ($reason === null) {
        return 0;
    }

    fwrite(STDERR, "Blocked by .claude/hooks/guard.php: {$reason}\n");

    return 2;
}

/** The refusal reason, or null when the call may go ahead. */
function guard_check(array $payload): ?string
{
    $tool = (string) ($payload['tool_name'] ?? '');
    $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];

    if ($tool === 'Bash') {
        return guard_check_command((string) ($input['command'] ?? ''));
    }

    if (in_array($tool, ['Write', 'Edit', 'MultiEdit', 'NotebookEdit'], true)) {
        $path = (string) ($input['file_path'] ?? $input['notebook_path'] ?? '');
        if (preg_match('#(^|/)vendor/#', $path) === 1) {
            return 'vendor/ belongs to Composer and is overwritten on the next install. '
                .'Change src/ instead, or override the method in a subclass.';
        }
    }

    return null;
}

function guard_check_command(string $command): ?string
{
    // Each simple command separated by ; && || | & or a newline, checked alone.
    foreach (preg_split('/&&|\|\||[;|&\n]|\$\(|`/', $command) ?: [] as $segment) {
        $words = guard_words($segment);
        if ($words === []) {
            continue;
        }

        $reason = match (basename($words[0])) {
            'rm' => guard_check_rm(array_slice($words, 1)),
            'git' => guard_check_git(array_slice($words, 1)),
            default => null,
        };

        if ($reason !== null) {
            return $reason;
        }
    }

    return null;
}

/**
 * Shell words with quotes removed; leading VAR=value, sudo, env and command
 * are skipped so `sudo rm -rf x` is read as `rm -rf x`.
 *
 * @return list<string>
 */
function guard_words(string $segment): array
{
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+/', $segment, $m);
    $words = array_map(static fn (string $w): string => trim($w, '"\''), $m[0]);

    while ($words !== [] && (in_array($words[0], ['sudo', 'env', 'command', 'exec'], true)
        || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]) === 1)) {
        array_shift($words);
    }

    return array_values($words);
}

/** @param list<string> $args */
function guard_check_rm(array $args): ?string
{
    $recursive = $force = false;
    $targets = [];

    foreach ($args as $arg) {
        if ($arg === '--recursive') {
            $recursive = true;
        } elseif ($arg === '--force') {
            $force = true;
        } elseif (preg_match('/^-[A-Za-z]+$/', $arg) === 1) {
            $recursive = $recursive || stripos($arg, 'r') !== false;
            $force = $force || str_contains($arg, 'f');
        } elseif ($arg !== '--') {
            $targets[] = rtrim(preg_replace('#^\./#', '', $arg) ?? $arg, '/');
        }
    }

    if (! $recursive || ! $force) {
        return null;
    }

    foreach ($targets as $target) {
        if (! in_array($target, RM_RF_ALLOWED, true)) {
            return "`rm -rf {$target}` is refused. Only build output may be force-removed ("
                .implode(', ', RM_RF_ALLOWED).'). Delete specific files, or ask the maintainer.';
        }
    }

    return $targets === [] ? '`rm -rf` with no explicit target is refused.' : null;
}

/** @param list<string> $args */
function guard_check_git(array $args): ?string
{
    // Skip git's own options: -C <dir>, -c <k=v>, --git-dir=..., etc.
    while ($args !== [] && str_starts_with($args[0], '-')) {
        $option = array_shift($args);
        if (in_array($option, ['-C', '-c'], true)) {
            array_shift($args);
        }
    }

    $sub = array_shift($args);

    return match ($sub) {
        'push' => guard_check_push($args),
        'add', 'commit' => guard_check_stage($sub, $args),
        default => null,
    };
}

/** @param list<string> $args */
function guard_check_push(array $args): ?string
{
    $force = $delete = false;
    $positional = [];

    foreach ($args as $arg) {
        if (in_array($arg, ['-f', '--force', '--mirror'], true) || str_starts_with($arg, '--force-with-lease')) {
            $force = true;
        } elseif (in_array($arg, ['-d', '--delete'], true)) {
            $delete = true;
        } elseif (! str_starts_with($arg, '-')) {
            $positional[] = $arg;
        }
    }

    $refspecs = array_slice($positional, 1); // the first one is the remote

    foreach ($refspecs as $refspec) {
        $plus = str_starts_with($refspec, '+');
        $colonDelete = str_starts_with($refspec, ':');
        $dest = preg_replace('#^refs/(heads|tags)/#', '', (string) substr(strrchr(':'.ltrim($refspec, '+'), ':'), 1));

        if (! guard_is_protected_ref($dest)) {
            continue;
        }
        if ($delete || $colonDelete) {
            return "deleting `{$dest}` on the remote is refused: it is the default branch or a published version.";
        }
        if ($force || $plus) {
            return "force-pushing `{$dest}` is refused: it rewrites the default branch or a published version. "
                .'Push a new branch and open a pull request; for a bad release, tag a new patch version.';
        }
    }

    if ($force && $refspecs === []) {
        return 'a force push with no explicit branch is refused (it may hit main). '
            .'Name the branch: `git push --force-with-lease origin my-branch`.';
    }

    return null;
}

/** Default branch, or a version tag such as v1.2.3 that Packagist and lock files already point at. */
function guard_is_protected_ref(string $ref): bool
{
    return in_array($ref, PROTECTED_BRANCHES, true) || preg_match('/^v?\d+\.\d+\.\d+/', $ref) === 1;
}

/** @param list<string> $args */
function guard_check_stage(string $sub, array $args): ?string
{
    if ($sub === 'add' && (in_array('-f', $args, true) || in_array('--force', $args, true))) {
        return '`git add --force` stages git-ignored files, which is how secrets get committed. '
            .'Stage the explicit paths without --force.';
    }

    foreach ($args as $arg) {
        if (! str_starts_with($arg, '-') && guard_is_secret_path($arg)) {
            return "`{$arg}` looks like a secret (env file or credential key) and must never be committed.";
        }
    }

    return null;
}

function guard_is_secret_path(string $path): bool
{
    $name = basename($path);

    if ($name === '.env.example' || $name === '.env.dist') {
        return false;
    }

    return $name === '.env'
        || str_starts_with($name, '.env.')
        || preg_match('/service[-_]?account.*\.json$|^credentials\.json$|\.(pem|p12|key)$/i', $name) === 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(guard_main());
}

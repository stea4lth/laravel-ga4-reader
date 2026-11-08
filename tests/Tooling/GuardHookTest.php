<?php

declare(strict_types=1);

/*
| The Claude Code PreToolUse hook in .claude/hooks/guard.php, driven the way
| Claude Code drives it: the tool call as JSON on stdin, exit 2 + stderr to
| block, exit 0 to allow. Each refusal has an allowed neighbour, so a guard
| that blocks everything fails here too.
*/

/** @return array{0: int, 1: string} [exit code, stderr] */
function runGuard(array $payload): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2).'/.claude/hooks/guard.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stderr];
}

function guardBash(string $command): array
{
    return runGuard(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
}

it('blocks', function (string $command): void {
    [$exit, $stderr] = guardBash($command);

    expect($exit)->toBe(2)->and($stderr)->toContain('Blocked by .claude/hooks/guard.php');
})->with([
    'force push to main' => 'git push --force origin main',
    'short flag, refs/heads' => 'git push -f origin refs/heads/main',
    'plus refspec' => 'git push origin +main',
    'lease to main' => 'git push --force-with-lease origin HEAD:main',
    'force with no branch' => 'git push --force',
    'moving a release tag' => 'git push --force origin v0.1.0',
    'deleting a release tag' => 'git push --delete origin v0.1.0',
    'colon delete of main' => 'git push origin :main',
    'mirror' => 'git push --mirror origin',
    'git -C option first' => 'git -C /tmp/x push -f origin main',
    // Options that take a value must not shift the remote/refspec positions.
    'repo= then force main' => 'git push --repo=origin --force main',
    'repo= with main first' => 'git push --repo=origin --force main feature',
    'repo value, short force' => 'git push --repo origin -f main',
    'push option value' => 'git push -o x --force main',
    'push option before remote' => 'git push -o ci.skip -f origin main',
    'push option attached' => 'git push -oci.skip --force origin main',
    'long push option value' => 'git push --push-option ci.skip --force origin main',
    'receive-pack value' => 'git push --receive-pack git-receive-pack -f origin main',
    'exec= value' => 'git push --exec=git-receive-pack --force origin main',
    'lease with a value' => 'git push --force-with-lease=main:abc123 origin main',
    'double dash ends options' => 'git push --force origin -- main',
    'clustered short flags' => 'git push -fu origin main',
    'cluster ending in o' => 'git push -fo ci.skip origin main',
    'plus with HEAD' => 'git push origin +HEAD:main',
    'plus after a feature' => 'git push origin feature +main',
    'plus full ref' => 'git push origin +refs/heads/main:refs/heads/main',
    'plus after push option' => 'git push -o x origin +main',
    'force with tags' => 'git push --force origin feature --tags',
    'force with all branches' => 'git push -f --all origin',
    'delete via short cluster' => 'git push -ud origin v0.1.0',
    'prune every branch' => 'git push --prune --all origin',
    'rm -rf of src' => 'rm -rf src',
    'rm -fr' => 'rm -fr tests',
    'split flags' => 'rm -r -f config',
    'long flags' => 'rm --recursive --force .',
    'rm -rf hidden after &&' => 'composer test && rm -rf ~',
    'sudo rm -rf' => 'sudo rm -rf /',
    'allowed plus disallowed target' => 'rm -rf vendor src',
    'adding .env' => 'git add .env',
    'adding .env.testing' => 'git add src/Foo.php .env.testing',
    'adding a service-account key' => 'git add storage/app/google/ga-service-account.json',
    'committing a key by path' => 'git commit -m wip credentials.json',
    'force-adding ignored files' => 'git add -f composer.lock',
]);

it('allows', function (string $command): void {
    expect(guardBash($command)[0])->toBe(0);
})->with([
    'plain push to main' => 'git push origin main',
    'force push to a feature branch' => 'git push --force-with-lease origin agent-setup',
    'pushing a new tag' => 'git push origin v0.2.0',
    'push option to a feature' => 'git push -o ci.skip origin feature',
    'repo= to a feature' => 'git push --repo=origin feature',
    'set upstream on a feature' => 'git push -u origin feature',
    'lease value on a feature' => 'git push --force-with-lease=feature:abc123 origin feature',
    'clustered force on a feature' => 'git push -fu origin feature',
    'push option named main, feature ref' => 'git push -o main origin feature',
    'tags without force' => 'git push origin --tags',
    'prune with a named branch' => 'git push --prune origin main',
    'rm -rf vendor' => 'rm -rf vendor',
    'rm -rf caches' => 'rm -rf ./vendor/ .phpunit.cache',
    'rm of one file' => 'rm src/Old.php',
    'rm -r without force' => 'rm -r build',
    'adding source' => 'git add src/GoogleAnalyticsTraffic.php tests/Feature/ReportShapeTest.php',
    'adding .env.example' => 'git add .env.example',
    'composer test' => 'composer test',
    'grep for force' => 'grep -rn "push --force" docs',
]);

it('blocks edits under vendor/ but not src/', function (): void {
    $edit = fn (string $path): int => runGuard([
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => $path, 'old_string' => 'a', 'new_string' => 'b'],
    ])[0];

    expect($edit('/repo/vendor/google/analytics-data/src/Foo.php'))->toBe(2)
        ->and($edit('vendor/autoload.php'))->toBe(2)
        ->and($edit('/repo/src/GoogleAnalyticsTraffic.php'))->toBe(0);
});

it('lets unknown tools and unreadable input through', function (): void {
    expect(runGuard(['tool_name' => 'Read', 'tool_input' => ['file_path' => 'src/x.php']])[0])->toBe(0)
        ->and(runGuard(['unexpected' => true])[0])->toBe(0);
});

#!/usr/bin/env php
<?php
/**
 * scripts/release_check.php
 *
 * RCH = Release Checklist Helper
 *
 * Usage:
 *   php scripts/release_check.php 3.4.1
 *   php scripts/release_check.php 3.4.1 --create-tag
 *   php scripts/release_check.php 3.4.1 --create-tag --push
 */

define('RCH_APP_ROOT', dirname(__DIR__));

function out(string $line): void {
    echo $line . PHP_EOL;
}

function fail(string $message, int $code = 1): never {
    out('ERROR: ' . $message);
    exit($code);
}

function resolveGitBinary(): string {
    $candidates = [
        'git',
        'C:/laragon/bin/git/cmd/git.exe',
        'C:/laragon/bin/git/bin/git.exe',
    ];

    foreach ($candidates as $candidate) {
        $cmd = escapeshellarg($candidate) . ' --version 2>&1';
        $lines = [];
        $exitCode = 1;
        exec($cmd, $lines, $exitCode);
        if ($exitCode === 0) {
            return $candidate;
        }
    }

    fail('Git binary was not found.');
}

function gitRun(string $git, string $repo, string $args): array {
    $cmd = escapeshellarg($git) . ' -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1';
    $lines = [];
    $exitCode = 1;
    exec($cmd, $lines, $exitCode);
    return [
        'exitCode' => $exitCode,
        'output' => implode("\n", $lines),
    ];
}

function normalizeVersionTag(string $version): string {
    $version = trim($version);
    if ($version === '') {
        fail('Missing version argument. Example: 3.4.1');
    }

    $tag = str_starts_with($version, 'v') ? $version : ('v' . $version);
    if (!preg_match('/^v\d+\.\d+\.\d+$/', $tag)) {
        fail('Version must be semantic in format x.y.z or vX.Y.Z');
    }

    return $tag;
}

function versionsLogHasTag(string $repo, string $tag): bool {
    $versionsFile = $repo . DIRECTORY_SEPARATOR . 'changelog.md';
    if (!is_file($versionsFile)) {
        return false;
    }

    $content = file_get_contents($versionsFile);
    if (!is_string($content) || $content === '') {
        return false;
    }

    return str_contains($content, '## ' . $tag);
}

$argv = $_SERVER['argv'] ?? [];
$versionArg = $argv[1] ?? '';
$tag = normalizeVersionTag($versionArg);

$createTag = in_array('--create-tag', $argv, true);
$pushTag = in_array('--push', $argv, true);
if ($pushTag) {
    $createTag = true;
}

$repo = RCH_APP_ROOT;
$git = resolveGitBinary();
out('RCH start for ' . $tag);
out('Repo: ' . $repo);
out('Git:  ' . $git);

$inside = gitRun($git, $repo, 'rev-parse --is-inside-work-tree');
if ($inside['exitCode'] !== 0 || trim($inside['output']) !== 'true') {
    fail('Not a git repository: ' . $inside['output']);
}

$branch = gitRun($git, $repo, 'rev-parse --abbrev-ref HEAD');
$currentBranch = trim($branch['output']);
if ($branch['exitCode'] !== 0 || $currentBranch === '') {
    fail('Cannot read current branch: ' . $branch['output']);
}
out('Branch: ' . $currentBranch);

if ($currentBranch !== 'main') {
    fail('RCH requires branch main. Current branch: ' . $currentBranch);
}

$status = gitRun($git, $repo, 'status --porcelain');
if ($status['exitCode'] !== 0) {
    fail('Cannot read git status: ' . $status['output']);
}
if (trim($status['output']) !== '') {
    fail('Working tree is not clean. Commit/stash changes first.');
}
out('Working tree: clean');

$tagLocal = gitRun($git, $repo, 'tag -l ' . escapeshellarg($tag));
if ($tagLocal['exitCode'] !== 0) {
    fail('Cannot query local tags: ' . $tagLocal['output']);
}
if (trim($tagLocal['output']) !== '') {
    fail('Tag already exists locally: ' . $tag);
}
out('Local tag check: OK');

$origin = gitRun($git, $repo, 'remote get-url origin');
if ($origin['exitCode'] !== 0) {
    fail('Remote origin is not configured: ' . $origin['output']);
}
out('Origin: ' . trim($origin['output']));

$tagRemote = gitRun($git, $repo, 'ls-remote --tags origin ' . escapeshellarg($tag));
if ($tagRemote['exitCode'] !== 0) {
    out('WARN: Cannot check remote tags (network/auth issue): ' . $tagRemote['output']);
} elseif (trim($tagRemote['output']) !== '') {
    fail('Tag already exists on remote: ' . $tag);
} else {
    out('Remote tag check: OK');
}

if ($createTag && !versionsLogHasTag($repo, $tag)) {
    fail('Missing changelog.md entry for ' . $tag . '. Add section "## ' . $tag . '" before tagging.');
}

if ($createTag) {
    $create = gitRun(
        $git,
        $repo,
        'tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg('Release ' . $tag)
    );
    if ($create['exitCode'] !== 0) {
        fail('Tag creation failed: ' . $create['output']);
    }
    out('Tag created: ' . $tag);
}

if ($pushTag) {
    $push = gitRun($git, $repo, 'push origin ' . escapeshellarg($tag));
    if ($push['exitCode'] !== 0) {
        fail('Tag push failed: ' . $push['output']);
    }
    out('Tag pushed: ' . $tag);
}

out('RCH completed successfully.');

#!/usr/bin/env php
<?php

/*
 * A scriptable stand-in for the Restic binary used by the test suite.
 *
 * It reads its behaviour from "fake-restic.json" next to itself, records
 * every invocation (argv, environment, cwd) to "invocations.jsonl", and keeps
 * a tiny repository state in "repo-state" so `init` changes what `cat config`
 * reports afterwards.
 */

declare(strict_types=1);

$self = $_SERVER['argv'][0];
$dir = dirname($self);
$args = array_slice($_SERVER['argv'], 1);

$scenario = is_file($dir.'/fake-restic.json')
    ? (json_decode((string) file_get_contents($dir.'/fake-restic.json'), true) ?: [])
    : [];

file_put_contents($dir.'/invocations.jsonl', json_encode([
    'argv' => $args,
    'env' => getenv(),
    'cwd' => getcwd(),
])."\n", FILE_APPEND);

$command = $args[0] ?? '';
if (in_array($command, ['cat', 'list'], true)) {
    $command .= ' '.($args[1] ?? '');
}

$stateFile = $dir.'/repo-state';
$state = is_file($stateFile) ? trim((string) file_get_contents($stateFile)) : ($scenario['repository'] ?? 'ready');

$emit = static function (int $exit, string $stdout = '', string $stderr = ''): never {
    fwrite(STDOUT, $stdout);
    fwrite(STDERR, $stderr);
    exit($exit);
};

if (isset($scenario['sleep'][$command])) {
    usleep((int) ($scenario['sleep'][$command] * 1000000));
}

if (isset($scenario['commands'][$command])) {
    $override = $scenario['commands'][$command];
    $emit((int) ($override['exit'] ?? 0), (string) ($override['stdout'] ?? ''), (string) ($override['stderr'] ?? ''));
}

if (($scenario['leak'] ?? false) && $command !== 'version') {
    $password = is_string(getenv('RESTIC_PASSWORD_FILE')) ? (string) @file_get_contents((string) getenv('RESTIC_PASSWORD_FILE')) : '';
    $emit(1, 'stdout password='.trim($password)."\n", sprintf(
        "Fatal: request to https://%s:%s@s3.example.com/bucket failed\nAWS_SECRET_ACCESS_KEY=%s\nrepository password: %s\nX-Amz-Signature=abcdef0123 key %s\n",
        (string) getenv('AWS_ACCESS_KEY_ID'),
        (string) getenv('AWS_SECRET_ACCESS_KEY'),
        (string) getenv('AWS_SECRET_ACCESS_KEY'),
        trim($password),
        (string) getenv('AWS_ACCESS_KEY_ID'),
    ));
}

$repositoryId = is_file($dir.'/repo-id') ? trim((string) file_get_contents($dir.'/repo-id')) : ($scenario['repository_id'] ?? str_repeat('ab', 32));

// Snapshots: scenario-provided ones plus those created by `backup` (stateful).
$snapshotsFile = $dir.'/snapshots.json';
$stored = is_file($snapshotsFile) ? (json_decode((string) file_get_contents($snapshotsFile), true) ?: []) : [];
$allSnapshots = [...($scenario['snapshots'] ?? []), ...$stored];

$optionValues = static function (array $args, string $flag): array {
    $values = [];
    foreach ($args as $i => $arg) {
        if ($arg === '--') {
            break;
        }
        if ($arg === $flag && isset($args[$i + 1])) {
            $values[] = $args[$i + 1];
        }
    }

    return $values;
};

$positional = static function (array $args): array {
    $index = array_search('--', $args, true);

    return $index === false ? [] : array_slice($args, $index + 1);
};

switch ($command) {
    case 'version':
        $emit(0, json_encode([
            'message_type' => 'version',
            'version' => $scenario['version'] ?? '0.19.1',
            'go_version' => 'go1.25.1',
            'go_os' => $scenario['go_os'] ?? 'linux',
            'go_arch' => $scenario['go_arch'] ?? 'amd64',
        ])."\n");

    case 'init':
        if ($state === 'ready') {
            $emit(1, '', "Fatal: create repository failed: config file already exists\n");
        }
        if ($state !== 'missing') {
            $emit(1, '', "Fatal: unexpected init in state {$state}\n");
        }
        file_put_contents($stateFile, 'ready');
        $emit(0, "created restic repository {$repositoryId}\n");

    case 'cat config':
    case 'snapshots':
    case 'list locks':
    case 'stats':
    case 'backup':
    case 'forget':
    case 'restore':
    case 'check':
    case 'prune':
        match ($state) {
            'missing' => $emit(10, '', "Fatal: repository does not exist: unable to open config file: Stat: The specified key does not exist.\nIs there a repository at the following location?\n"),
            'wrong_password' => $emit(12, '', "Fatal: wrong password or no key found\n"),
            'unreachable' => $emit(1, '', "Fatal: unable to open config file: Get \"https://s3.us-west-004.backblazeb2.com/\": dial tcp: lookup s3.us-west-004.backblazeb2.com: no such host\n"),
            'credentials' => $emit(1, '', "Fatal: unable to open config file: Stat: The AWS Access Key Id you provided does not exist in our records. InvalidAccessKeyId\n"),
            'locked' => $emit(11, '', "Fatal: unable to create lock in backend: repository is already locked exclusively\n"),
            default => null,
        };

        if ($command === 'backup') {
            $id = hash('sha256', uniqid('snapshot', true).random_bytes(8));
            $stored[] = [
                'id' => $id,
                'short_id' => substr($id, 0, 8),
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
                'tags' => $optionValues($args, '--tag'),
                'paths' => $positional($args),
                'hostname' => $optionValues($args, '--host')[0] ?? 'host',
            ];
            file_put_contents($snapshotsFile, json_encode($stored));
            $emit(0, json_encode(['message_type' => 'status', 'percent_done' => 1])."\n".json_encode(['message_type' => 'summary', 'snapshot_id' => $id, 'files_new' => 1])."\n");
        }

        if ($command === 'snapshots') {
            // One --tag value: comma-separated tags must ALL match (restic AND semantics);
            // several --tag flags are alternatives (OR).
            $tagGroups = array_map(static fn (string $group): array => explode(',', $group), $optionValues($args, '--tag'));
            $ids = $positional($args);
            $selected = array_values(array_filter($allSnapshots, static function (array $snapshot) use ($tagGroups, $ids): bool {
                if ($ids !== [] && ! in_array($snapshot['id'], $ids, true)) {
                    return false;
                }
                if ($tagGroups === []) {
                    return true;
                }
                foreach ($tagGroups as $group) {
                    if (array_diff($group, $snapshot['tags'] ?? []) === []) {
                        return true;
                    }
                }

                return false;
            }));
            $emit(0, json_encode($selected)."\n");
        }

        if ($command === 'stats') {
            $emit(0, json_encode(['total_size' => (int) ($scenario['restore_size'] ?? 1024), 'total_file_count' => 1])."\n");
        }

        if ($command === 'forget') {
            $ids = $positional($args);
            if ($ids === []) {
                $emit(1, '', "no exact snapshot IDs supplied\n");
            }
            $stored = array_values(array_filter($stored, static fn (array $snapshot): bool => ! in_array($snapshot['id'], $ids, true)));
            file_put_contents($snapshotsFile, json_encode($stored));
            $emit(0, json_encode(['success' => true])."\n");
        }

        if ($command === 'restore') {
            $id = $args[1] ?? '';
            $target = $optionValues($args, '--target')[0] ?? null;
            $matched = array_values(array_filter($allSnapshots, static fn (array $snapshot): bool => $snapshot['id'] === $id));
            if (count($matched) !== 1 || ! is_string($target)) {
                $emit(1, '', "unknown exact snapshot or target\n");
            }
            foreach ($matched[0]['paths'] as $root) {
                $relative = ltrim(str_replace('\\', '/', $root), '/');
                $relative = preg_replace('~^([A-Za-z]):/~', '$1/', $relative);
                if (! is_string($relative) || str_contains($relative, '..')) {
                    $emit(1, '', "unsafe path\n");
                }
                @mkdir($target.'/'.$relative, 0700, true);
                file_put_contents($target.'/'.$relative.'/restored-fixture.txt', 'restored');
            }
            $emit(0, json_encode(['message_type' => 'summary', 'files_restored' => 1])."\n");
        }

        match ($command) {
            'cat config' => $emit(0, json_encode(['version' => 2, 'id' => $repositoryId, 'chunker_polynomial' => '3da3358b4c2d4f'])."\n"),
            'list locks' => $emit(0, implode("\n", $scenario['locks'] ?? []).(($scenario['locks'] ?? []) === [] ? '' : "\n")),
            default => $emit(0, json_encode(['message_type' => 'summary', 'argv' => $args])."\n"),
        };

    default:
        $emit(1, '', "unknown command {$command}\n");
}

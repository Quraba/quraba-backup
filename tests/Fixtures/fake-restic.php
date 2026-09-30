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

$repositoryId = str_repeat('ab', 32);

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

        match ($command) {
            'cat config' => $emit(0, json_encode(['version' => 2, 'id' => $repositoryId, 'chunker_polynomial' => '3da3358b4c2d4f'])."\n"),
            'snapshots' => $emit(0, json_encode($scenario['snapshots'] ?? [])."\n"),
            'list locks' => $emit(0, implode("\n", $scenario['locks'] ?? []).(($scenario['locks'] ?? []) === [] ? '' : "\n")),
            default => $emit(0, json_encode(['message_type' => 'summary', 'argv' => $args])."\n"),
        };

    default:
        $emit(1, '', "unknown command {$command}\n");
}

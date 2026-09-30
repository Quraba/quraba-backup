<?php

/*
 * Child process used by the locking tests: acquires a package lock in the
 * given private root, reports it, then idles until it is killed. It never
 * releases the lock itself, so a successful re-acquisition by the parent after
 * the kill proves the kernel released it on process death.
 *
 * Usage: php hold-lock.php <private-root> <lock-name> [workspace]
 */

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Config\Repository;
use Psr\Log\NullLogger;
use Quraba\Backup\Coordination\FileLockManager;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Workspace\WorkspaceManager;

[$script, $root, $lock] = $argv + [null, null, null];
$mode = $argv[3] ?? 'lock';

$paths = PackagePaths::fromConfig(new Repository([
    'quraba-backup' => ['paths' => ['root' => $root]],
    'restic' => [],
]));

try {
    if ($mode === 'workspace') {
        $workspace = (new WorkspaceManager($paths, new NullLogger))->create();
        fwrite(STDOUT, 'WORKSPACE '.$workspace->id."\n");
    } else {
        $handle = (new FileLockManager($paths))->acquire(LockName::from((string) $lock), 'test child');
        fwrite(STDOUT, "LOCKED\n");
    }
} catch (Throwable $exception) {
    fwrite(STDOUT, 'REFUSED '.get_class($exception)."\n");
    exit(3);
}

fflush(STDOUT);

// Idle until killed (bounded so a broken test can never leave it running forever).
for ($i = 0; $i < 600; $i++) {
    usleep(100000);
}

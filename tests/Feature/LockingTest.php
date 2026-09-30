<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Coordination\FileLockManager;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Exceptions\LockUnavailable;
use Quraba\Backup\Exceptions\OperationBusy;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Tests\Support\ChildProcess;
use Quraba\Backup\Tests\TestCase;

final class LockingTest extends TestCase
{
    private function locks(): FileLockManager
    {
        return $this->app->make(FileLockManager::class);
    }

    public function test_lock_is_exclusive_within_a_process_and_released_explicitly(): void
    {
        $handle = $this->locks()->acquire(LockName::GlobalOperation, 'first');

        try {
            $this->locks()->acquire(LockName::GlobalOperation, 'second');
            self::fail('The second acquisition must be refused.');
        } catch (OperationBusy $exception) {
            self::assertSame('operation.busy', $exception->failureCode());
        }

        self::assertTrue($this->locks()->isHeldElsewhere(LockName::GlobalOperation));

        $handle->release();
        $handle->release(); // idempotent

        self::assertFalse($this->locks()->isHeldElsewhere(LockName::GlobalOperation));
        $this->locks()->acquire(LockName::GlobalOperation, 'third')->release();
    }

    public function test_second_process_is_refused_and_lock_is_released_when_holder_dies(): void
    {
        [$child, $status] = ChildProcess::start($this->sandbox.'/private', LockName::GlobalOperation->value);

        try {
            self::assertSame('LOCKED', $status);

            try {
                $this->locks()->acquire(LockName::GlobalOperation, 'parent');
                self::fail('A lock held by another process must refuse acquisition.');
            } catch (OperationBusy) {
                self::addToAssertionCount(1);
            }

            self::assertTrue($this->app->make(OperationCoordinator::class)->isWriteOperationRunning());
        } finally {
            ChildProcess::kill($child);
        }

        // The child never released its lock; the kernel did when it died.
        $handle = $this->locks()->acquire(LockName::GlobalOperation, 'after crash');
        self::assertFalse($handle->isReleased());
        $handle->release();
    }

    public function test_other_process_holding_the_global_lock_blocks_every_write_operation_class(): void
    {
        [$child] = ChildProcess::start($this->sandbox.'/private', LockName::GlobalOperation->value);

        try {
            foreach ([null, LockName::Restore, LockName::Maintenance] as $class) {
                try {
                    $this->app->make(OperationCoordinator::class)->beginWriteOperation('parent', $class);
                    self::fail('Only one write-affecting operation may run at a time.');
                } catch (OperationBusy) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            ChildProcess::kill($child);
        }
    }

    public function test_coordinator_releases_the_global_lock_when_the_class_lock_is_busy(): void
    {
        [$child] = ChildProcess::start($this->sandbox.'/private', LockName::Restore->value);

        try {
            try {
                $this->app->make(OperationCoordinator::class)->beginWriteOperation('restore', LockName::Restore);
                self::fail('The restore lock is held elsewhere.');
            } catch (OperationBusy) {
                self::addToAssertionCount(1);
            }

            // The global lock taken before the failure must have been released.
            self::assertFalse($this->locks()->isHeldElsewhere(LockName::GlobalOperation));
        } finally {
            ChildProcess::kill($child);
        }
    }

    public function test_held_locks_release_in_reverse_order(): void
    {
        $locks = $this->app->make(OperationCoordinator::class)->beginWriteOperation('maintenance', LockName::Maintenance);

        self::assertTrue($locks->holds(LockName::GlobalOperation));
        self::assertTrue($locks->holds(LockName::Maintenance));

        $locks->release();

        self::assertFalse($locks->holds(LockName::GlobalOperation));
        $this->locks()->acquire(LockName::Maintenance, 'again')->release();
    }

    public function test_locking_fails_closed_when_the_lock_directory_is_unusable(): void
    {
        // A regular file where the lock directory should be.
        file_put_contents($this->sandbox.'/not-a-directory', 'x');
        $this->config()->set('quraba-backup.paths.locks', $this->sandbox.'/not-a-directory');
        $this->app->forgetInstance(PackagePaths::class);
        $this->app->forgetInstance(FileLockManager::class);
        $this->app->forgetInstance(LockManager::class);

        $this->expectException(LockUnavailable::class);
        $this->app->make(LockManager::class)->acquire(LockName::GlobalOperation, 'x');
    }

    public function test_probing_never_creates_lock_files(): void
    {
        self::assertFalse($this->locks()->isHeldElsewhere(LockName::Restore));
        self::assertFileDoesNotExist($this->sandbox.'/private/locks/restore.lock');
    }
}

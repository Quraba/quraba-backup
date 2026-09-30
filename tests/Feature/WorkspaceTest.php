<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Tests\Support\ChildProcess;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceDeleter;
use Quraba\Backup\Workspace\WorkspaceManager;
use RuntimeException;

final class WorkspaceTest extends TestCase
{
    private function manager(): WorkspaceManager
    {
        return $this->app->make(WorkspaceManager::class);
    }

    private function base(): string
    {
        return str_replace('\\', '/', (string) realpath($this->sandbox.'/private/work'));
    }

    public function test_each_operation_gets_a_unique_private_root_with_all_areas(): void
    {
        $first = $this->manager()->create();
        $second = $this->manager()->create();

        self::assertNotSame($first->id, $second->id);
        self::assertMatchesRegularExpression('~/op-[0-9A-HJKMNP-TV-Z]{26}$~', $first->root());

        foreach (WorkspaceArea::cases() as $area) {
            self::assertDirectoryExists($first->area($area));
        }

        $first->cleanup();
        $second->cleanup();
    }

    public function test_paths_are_contained_and_traversal_is_refused(): void
    {
        $workspace = $this->manager()->create();

        self::assertSame($workspace->area(WorkspaceArea::Database).'/dump.sql', $workspace->path(WorkspaceArea::Database, 'dump.sql'));

        foreach (['../escape', 'a/../../x', '/etc/passwd', '..', 'a\\..\\b'] as $relative) {
            try {
                $workspace->path(WorkspaceArea::Temp, $relative);
                self::fail(sprintf('[%s] must be refused.', $relative));
            } catch (WorkspaceViolation) {
                self::addToAssertionCount(1);
            }
        }

        $workspace->cleanup();
    }

    public function test_symlinks_cannot_escape_the_workspace(): void
    {
        $workspace = $this->manager()->create();
        $outside = $this->sandbox.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/precious.txt', 'keep me');

        $link = $workspace->area(WorkspaceArea::Temp).'/escape';

        if (! @symlink($outside, $link)) {
            $workspace->cleanup();
            self::markTestSkipped('This platform/user cannot create symbolic links.');
        }

        try {
            $workspace->path(WorkspaceArea::Temp, 'escape/precious.txt');
            self::fail('A path through a symlink leaving the workspace must be refused.');
        } catch (WorkspaceViolation) {
            self::addToAssertionCount(1);
        }

        $report = $workspace->cleanup();

        self::assertTrue($report->succeeded(), implode(' ', $report->errors));
        self::assertFileExists($outside.'/precious.txt', 'Cleanup must remove the link, never its target.');
        self::assertSame('keep me', file_get_contents($outside.'/precious.txt'));
    }

    public function test_cleanup_removes_only_its_own_tree_and_is_idempotent(): void
    {
        $mine = $this->manager()->create();
        $other = $this->manager()->create();

        file_put_contents($mine->directory(WorkspaceArea::Archive, 'nested/deeper').'/file.bin', 'data');
        file_put_contents($other->path(WorkspaceArea::Media, 'keep.bin'), 'other');

        $report = $mine->cleanup();

        self::assertTrue($report->succeeded());
        self::assertTrue($report->removed);
        self::assertDirectoryDoesNotExist($mine->root());
        self::assertFileExists($other->path(WorkspaceArea::Media, 'keep.bin'));

        $again = $mine->cleanup();
        self::assertTrue($again->alreadyAbsent);
        self::assertFalse($again->removed);

        $this->expectException(WorkspaceViolation::class);
        $mine->area(WorkspaceArea::Temp);
    }

    public function test_deleter_refuses_anything_that_is_not_an_operation_workspace(): void
    {
        $foreign = $this->sandbox.'/private/work/not-a-workspace';
        mkdir($foreign, 0700, true);
        file_put_contents($foreign.'/x', 'x');

        self::assertNotSame([], WorkspaceDeleter::deleteTree($foreign, $this->base()));
        self::assertFileExists($foreign.'/x');

        $elsewhere = $this->sandbox.'/op-01J00000000000000000000000';
        mkdir($elsewhere);
        self::assertNotSame([], WorkspaceDeleter::deleteTree($elsewhere, $this->base()), 'A workspace-named directory outside the base must not be deleted.');
        self::assertDirectoryExists($elsewhere);
    }

    public function test_using_always_cleans_up_and_preserves_the_primary_error(): void
    {
        $root = null;

        try {
            $this->manager()->using(function ($workspace) use (&$root): void {
                $root = $workspace->root();
                throw new RuntimeException('primary failure');
            });
            self::fail('The primary error must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('primary failure', $exception->getMessage());
        }

        self::assertIsString($root);
        self::assertDirectoryDoesNotExist($root);
        self::assertSame(42, $this->manager()->using(static fn (): int => 42));
    }

    public function test_listing_reports_active_and_abandoned_without_deleting(): void
    {
        $abandoned = $this->manager()->create();
        $abandonedRoot = $abandoned->root();
        unset($abandoned); // owner "crashed": lock released, directory kept

        [$child, $status] = ChildProcess::start($this->sandbox.'/private', '', 'workspace');

        try {
            self::assertStringStartsWith('WORKSPACE ', $status);
            $activeId = substr($status, strlen('WORKSPACE '));

            $list = $this->manager()->list(0);
            $byId = [];
            foreach ($list as $info) {
                $byId[$info->id] = $info;
            }

            self::assertTrue($byId[$activeId]->active);
            self::assertFalse($byId[$activeId]->abandoned);
            self::assertTrue($byId[basename($abandonedRoot) === '' ? '' : substr(basename($abandonedRoot), 3)]->abandoned);
            self::assertDirectoryExists($abandonedRoot, 'Listing never deletes.');

            // Execute cleanup: the abandoned workspace goes, the active one stays.
            $reports = $this->manager()->cleanupAbandoned(0);

            self::assertCount(1, $reports);
            self::assertTrue($reports[0]->succeeded());
            self::assertDirectoryDoesNotExist($abandonedRoot);
            self::assertDirectoryExists($this->base().'/op-'.$activeId, 'An active workspace is never removed.');
        } finally {
            ChildProcess::kill($child);
        }

        // Once the owner process died, its workspace becomes abandoned.
        $after = $this->manager()->abandoned(0);
        self::assertSame([$activeId], array_map(static fn ($info): string => $info->id, $after));
    }

    public function test_workspace_without_owner_lock_is_never_deleted_automatically(): void
    {
        $workspace = $this->manager()->create();
        $root = $workspace->root();
        unset($workspace);

        unlink($root.'.lock');

        $reports = $this->manager()->cleanupAbandoned(0);

        self::assertCount(1, $reports);
        self::assertFalse($reports[0]->succeeded());
        self::assertStringContainsString('cannot be proven', implode(' ', $reports[0]->errors));
        self::assertDirectoryExists($root);
    }

    public function test_abandonment_threshold_is_respected(): void
    {
        $workspace = $this->manager()->create();
        unset($workspace);

        self::assertSame([], $this->manager()->abandoned(3600), 'A fresh inactive workspace is not abandoned yet.');
        self::assertCount(1, $this->manager()->abandoned(0));
    }

    public function test_workspace_commands_plan_by_default_and_execute_explicitly(): void
    {
        $workspace = $this->manager()->create();
        $root = $workspace->root();
        unset($workspace);

        $this->artisan('backup:workspace:list', ['--older-than' => 1])->assertSuccessful();

        $this->artisan('backup:workspace:cleanup', ['--older-than' => 0])->assertFailed();

        // Threshold of 1 hour: a fresh workspace is not yet abandoned.
        $this->artisan('backup:workspace:cleanup', ['--older-than' => 1, '--execute' => true])
            ->expectsOutputToContain('No abandoned workspaces')
            ->assertSuccessful();

        self::assertDirectoryExists($root);

        $this->travel(2)->hours();

        $this->artisan('backup:workspace:cleanup', ['--older-than' => 1])
            ->expectsOutputToContain('PLAN ONLY')
            ->assertSuccessful();
        self::assertDirectoryExists($root, 'Plan mode must not delete.');

        $this->artisan('backup:workspace:cleanup', ['--older-than' => 1, '--execute' => true])->assertSuccessful();
        self::assertDirectoryDoesNotExist($root);
    }
}

<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Live\MediaStaging;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\TestCase;

/**
 * Failure at every media boundary of a live restore. Media is replaced by
 * renames only; nothing is copied, overlaid or rolled back.
 */
final class LiveRestoreMediaFailureTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    private function stagedTree(RestoreJournal $journal, string $root = 'uploads'): string
    {
        return (string) $journal->media()[$root]['staged'];
    }

    private function currentJournal(): RestoreJournal
    {
        return $this->onlyJournal();
    }

    public function test_an_incomplete_staged_tree_is_detected_before_anything_is_swapped(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('safety_backup.settled', function (): void {
            unlink($this->stagedTree($this->currentJournal()).'/docs/readme.txt');
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status']);
        self::assertSame('restore.media_mapping_failed', $report['error']['code']);
        self::assertStringContainsString('incomplete or changed', $report['error']['message']);
        self::assertFalse($report['destructive_boundary_crossed']);
        $this->assertMediaIsStateB();
        self::assertSame([], $this->parkedDirectories());
        self::assertSame('staged', $this->journal($report['restore_uuid'])->rootState('uploads'));
    }

    public function test_when_the_same_filesystem_proof_no_longer_holds_nothing_is_copied_instead(): void
    {
        $source = $this->recoveryPointOfStateA();
        $crossDevice = false;
        $this->app->instance(MediaStaging::class, new MediaStaging($this->app->publicPath(), static function (string $path) use (&$crossDevice): ?int {
            $stat = @stat($path);

            // After planning, the staging area "moves" to another device.
            return $stat === false ? null : ($crossDevice && str_contains(str_replace('\\', '/', $path), '/private/') ? 999_999 : $stat['dev']);
        }));
        $this->faults->at('media.before_swap', static function () use (&$crossDevice): void {
            $crossDevice = true;
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status'], 'Detected before this root\'s own boundary record.');
        self::assertSame('restore.media_apply_failed', $report['error']['code']);
        self::assertStringContainsString('will not be copied', $report['error']['message']);
        $this->assertMediaIsStateB();
        self::assertSame([], $this->parkedDirectories());
    }

    public function test_live_to_parked_rename_failure_leaves_the_live_root_in_place(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('media.swap_starting', function (): void {
            // Something occupies the parked path: the rename must not happen.
            mkdir((string) $this->currentJournal()->media()['uploads']['parked'], 0700);
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $report['status']);
        self::assertSame('restore.media_apply_failed', $report['error']['code']);
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('swap_starting', $journal->rootState('uploads'));
        self::assertTrue($journal->crossedDestructiveBoundary(), 'The boundary record precedes the rename, so the outcome is not provable as failed.');
        $this->assertMediaIsStateB();
        self::assertDirectoryExists($this->stagedTree($journal), 'The staged tree is kept as evidence.');
        self::assertTrue($this->quiescenceProvider->active);
    }

    public function test_crash_after_parking_keeps_the_parked_tree_and_never_renames_it_back(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->crashAt('media.parked');

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('parked', $journal->rootState('uploads'));
        self::assertDirectoryDoesNotExist($this->mediaRoot, 'No automatic rollback: the live path stays empty.');
        $parked = $this->parkedDirectories();
        self::assertCount(1, $parked);
        self::assertSame($parked, $report['parked_media']);
        self::assertSame('image-B-changed', file_get_contents($parked[0].'/uploads/a.jpg'));
        self::assertDirectoryExists($this->stagedTree($journal));

        // Reconciliation describes exactly that and changes nothing.
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(3, $reconcileExit);
        self::assertSame('indeterminate', $reconciled['outcome']);
        self::assertSame('absent', $reconciled['evidence']['media']['uploads']['live_is']);
        self::assertTrue($reconciled['evidence']['media']['uploads']['parked_exists']);
        self::assertTrue($reconciled['evidence']['media']['uploads']['staged_exists']);
        self::assertStringContainsString('parked', implode("\n", $reconciled['guidance']));
        self::assertDirectoryDoesNotExist($this->mediaRoot);
        self::assertCount(1, $this->parkedDirectories());
    }

    public function test_staged_to_live_rename_failure_is_indeterminate_without_overlay(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('media.parked', function (): void {
            $staged = $this->stagedTree($this->currentJournal());
            rename($staged, $staged.'-moved-away');
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        self::assertSame('restore.media_apply_failed', $report['error']['code']);
        self::assertSame('parked', $this->journal($report['restore_uuid'])->rootState('uploads'));
        self::assertDirectoryDoesNotExist($this->mediaRoot, 'Nothing is created at the live path as a fallback.');
        self::assertCount(1, $this->parkedDirectories());
    }

    public function test_crash_after_activation_is_proven_complete_by_reconciliation(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->killAt('media.activated');

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        $journal = $this->journal($report['restore_uuid']);
        self::assertNull($journal->terminalState(), 'A killed process writes no terminal record.');
        self::assertSame('activated', $journal->rootState('uploads'));
        $this->assertMediaIsStateA();

        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(0, $reconcileExit, (string) json_encode($reconciled));
        self::assertSame('completed', $reconciled['outcome']);
        self::assertSame('restored_tree', $reconciled['evidence']['media']['uploads']['live_is']);
        self::assertSame('completed', $this->journal($report['restore_uuid'])->resolutionOutcome());
        self::assertCount(1, $this->parkedDirectories(), 'Parked data is still only removed on request.');
    }

    public function test_verification_failure_after_activation_is_indeterminate(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('media.activated', function (): void {
            file_put_contents($this->mediaRoot.'/uploads/written-during-restore.txt', 'intruder');
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        self::assertSame('restore.media_verification_failed', $report['error']['code']);
        self::assertSame('activated', $this->journal($report['restore_uuid'])->rootState('uploads'));
        self::assertCount(1, $this->parkedDirectories());

        // Not provable as complete either: the live tree is not the staged tree.
        [, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame('indeterminate', $reconciled['outcome']);
        self::assertSame('different_tree', $reconciled['evidence']['media']['uploads']['live_is']);
    }

    public function test_multiple_roots_are_not_one_transaction_first_succeeds_second_fails(): void
    {
        // A second logical media root, with its own state A and B.
        $second = $this->sandbox.'/media-two';
        mkdir($second, 0700);
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot], 'reports' => ['path' => $second]]);
        $this->refreshLiveServices();
        file_put_contents($second.'/report.txt', 'report-a');
        $source = $this->recoveryPointOfStateA();
        file_put_contents($second.'/report.txt', 'report-B');

        $this->faults->at('media.swap_starting', static function (array $context): void {
            if ($context['root'] === 'reports') {
                throw new \RuntimeException('second root cannot be swapped');
            }
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $report['status']);
        $journal = $this->journal($report['restore_uuid']);

        // Each root is journaled on its own; the first one is NOT undone.
        self::assertSame('verified', $journal->rootState('uploads'));
        self::assertSame('swap_starting', $journal->rootState('reports'));
        $this->assertMediaIsStateA();
        self::assertSame('report-B', file_get_contents($second.'/report.txt'));
        self::assertCount(1, $this->parkedDirectories(), 'The first root\'s old tree stays parked until the WHOLE restore is verified.');

        [, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame('indeterminate', $reconciled['outcome']);
        self::assertTrue($reconciled['evidence']['media']['uploads']['proven_final']);
        self::assertFalse($reconciled['evidence']['media']['reports']['proven_final']);
        self::assertSame('original_tree', $reconciled['evidence']['media']['reports']['live_is']);

        // Parked data is refused for cleanup while the restore is not completed.
        [$cleanupExit, $cleanup] = $this->reconcileRestore($report['restore_uuid'], ['--cleanup-parked' => true]);
        self::assertSame(1, $cleanupExit);
        self::assertSame('restore.journal_failed', $cleanup['error']['code']);
        self::assertCount(1, $this->parkedDirectories());
    }

    public function test_a_public_or_overlapping_configured_staging_directory_is_refused(): void
    {
        $source = $this->recoveryPointOfStateA();
        // The workspace is "on another filesystem", so only a configured
        // staging directory could be used.
        $this->app->instance(MediaStaging::class, new MediaStaging($this->app->publicPath(), static function (string $path): ?int {
            $stat = @stat($path);

            return $stat === false ? null : (str_contains(str_replace('\\', '/', $path), '/private/') ? 999_999 : $stat['dev']);
        }));

        // No staging configured: refused, never the public directory.
        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('restore.media_staging_unavailable', $report['error']['code']);
        $this->assertMediaIsStateB();

        // A web-public staging directory is refused.
        @mkdir($this->app->publicPath('staging'), 0700, true);
        foreach ([$this->app->publicPath('staging') => 'public web directory', $this->mediaRoot.'/uploads' => 'overlaps a live media root'] as $staging => $reason) {
            $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot, 'staging' => $staging]]);
            $this->refreshLiveServices();
            [$exit, $report] = $this->liveRestore($source->uuid, 'media');
            self::assertSame(1, $exit);
            self::assertSame('restore.media_staging_unavailable', $report['error']['code']);
            self::assertStringContainsString($reason, $report['error']['message']);
        }
        @rmdir($this->app->publicPath('staging'));
        $this->assertMediaIsStateB();

        // A private one on the destination's filesystem works, and the
        // restore's own staging directory is removed afterwards.
        $private = $this->sandbox.'/private-staging';
        mkdir($private, 0700);
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot, 'staging' => $private]]);
        $this->refreshLiveServices();
        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(0, $exit, (string) json_encode($report));
        $this->assertMediaIsStateA();
        self::assertStringStartsWith(str_replace('\\', '/', $private).'/quraba-restore-'.$report['restore_uuid'], str_replace('\\', '/', (string) $this->journal($report['restore_uuid'])->media()['uploads']['staged']));
        self::assertSame(['.', '..'], scandir($private), 'The package removed the staging directory it created.');
    }
}

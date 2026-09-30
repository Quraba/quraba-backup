<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use Carbon\CarbonImmutable;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerification;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Exceptions\ArchiveUploadFailed;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\ManifestStoreFailed;
use Quraba\Backup\Manifest\ManifestBuilder;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Tests\TestCase;

final class RemoteStorageTest extends TestCase
{
    private function newRun(): BackupRun
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 01:02:03', 'UTC'));

        try {
            return BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function local(string $contents): ArchiveVerification
    {
        $path = $this->sandbox.'/local-'.bin2hex(random_bytes(4)).'.zip';
        file_put_contents($path, $contents);

        return new ArchiveVerification($path, hash('sha256', $contents), strlen($contents), ['a'], 'aes256', []);
    }

    private function store(): ArchiveStore
    {
        return $this->app->make(ArchiveStore::class);
    }

    public function test_archive_paths_are_deterministic_per_run(): void
    {
        $run = $this->newRun();

        self::assertSame(
            'quraba-backup/'.self::APP_ID.'/archives/2026/10/01/'.$run->uuid.'/application.zip',
            $this->store()->locatorFor($run),
        );
        self::assertSame(
            'quraba-backup/'.self::APP_ID.'/manifests/2026/10/01/'.$run->uuid.'.json',
            $this->app->make(ManifestStore::class)->locatorFor($run),
        );
    }

    public function test_upload_is_proven_by_existence_and_exact_size(): void
    {
        $run = $this->newRun();
        $local = $this->local('archive-bytes');

        $stored = $this->store()->store($run, $local);

        self::assertFalse($stored->adopted);
        self::assertSame($local->sha256, $stored->sha256);
        self::assertSame('archive-bytes', file_get_contents($this->bucketPath($stored->locator)));
    }

    public function test_identical_existing_object_is_adopted_after_an_interrupted_run(): void
    {
        $run = $this->newRun();
        $local = $this->local('archive-bytes');
        $locator = $this->store()->locatorFor($run);

        // A previous attempt uploaded, then crashed before the catalog update.
        mkdir(dirname($this->bucketPath($locator)), 0700, true);
        file_put_contents($this->bucketPath($locator), 'archive-bytes');

        $stored = $this->store()->store($run, $local);

        self::assertTrue($stored->adopted);
        self::assertSame($local->sha256, $stored->sha256);
    }

    public function test_conflicting_objects_are_never_overwritten(): void
    {
        $run = $this->newRun();
        $locator = $this->store()->locatorFor($run);
        mkdir(dirname($this->bucketPath($locator)), 0700, true);

        foreach (['different!!!!', 'diff-size'] as $existing) {
            file_put_contents($this->bucketPath($locator), $existing);

            try {
                $this->store()->store($run, $this->local('archive-bytes'));
                self::fail('A different object at the run\'s path must be a collision.');
            } catch (ArchiveUploadFailed $exception) {
                self::assertSame('archive.collision', $exception->failureCode());
            }

            self::assertSame($existing, file_get_contents($this->bucketPath($locator)), 'Never overwritten.');
        }
    }

    public function test_partial_remote_object_is_never_adopted(): void
    {
        $run = $this->newRun();
        $locator = $this->store()->locatorFor($run);
        mkdir(dirname($this->bucketPath($locator)), 0700, true);
        file_put_contents($this->bucketPath($locator), 'archive-');

        $this->expectException(ArchiveUploadFailed::class);
        $this->store()->store($run, $this->local('archive-bytes'));
    }

    public function test_manifests_are_immutable(): void
    {
        $run = $this->newRun();
        $store = $this->app->make(ManifestStore::class);
        $manifest = ManifestBuilder::encode(['run_uuid' => $run->uuid, 'schema_version' => 1, 'status' => 'completed']);

        $first = $store->put($run, $manifest);
        self::assertFalse($first->adopted);

        // Identical content (even differently ordered) is adopted.
        $again = $store->put($run, (string) json_encode(['status' => 'completed', 'schema_version' => 1, 'run_uuid' => $run->uuid]));
        self::assertTrue($again->adopted);
        self::assertSame($first->sha256, $again->sha256);

        try {
            $store->put($run, ManifestBuilder::encode(['run_uuid' => $run->uuid, 'schema_version' => 1, 'status' => 'partial']));
            self::fail('A different manifest for the same run is a collision.');
        } catch (ManifestStoreFailed $exception) {
            self::assertSame('manifest.collision', $exception->failureCode());
        }

        self::assertSame($manifest, file_get_contents($this->bucketPath($first->locator)));
    }

    public function test_object_storage_refuses_the_restic_prefix(): void
    {
        $objects = $this->app->make(RemoteStorage::class)->objects();
        $resticRoot = $this->app->make(RemoteStorage::class)->layout()->resticRoot();

        foreach ([fn () => $objects->exists($resticRoot.'config'), fn () => $objects->write($resticRoot.'data/x', 'x'), fn () => $objects->listFiles($resticRoot)] as $attempt) {
            try {
                $attempt();
                self::fail('The Restic prefix must never be touched through object storage.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertDirectoryDoesNotExist($this->sandbox.'/b2/'.rtrim($resticRoot, '/'));
    }
}

<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Retention\RetentionTombstone;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;
use Symfony\Component\Uid\Uuid;

/**
 * Optional, opt-in test against a real Backblaze B2 bucket. Never part of
 * normal CI. Requires:
 *
 *   QURABA_BACKUP_TEST_B2=1, QURABA_BACKUP_TEST_RESTIC_BINARY,
 *   QURABA_BACKUP_TEST_B2_ENDPOINT, QURABA_BACKUP_TEST_B2_REGION,
 *   QURABA_BACKUP_TEST_B2_BUCKET, QURABA_BACKUP_TEST_B2_KEY_ID,
 *   QURABA_BACKUP_TEST_B2_APPLICATION_KEY
 *
 * It only inspects a generated, throwaway prefix on Linux. The lifecycle
 * test initializes Restic inside that prefix and cleans only its own objects.
 */
#[Group('b2-integration')]
final class B2RepositoryTest extends TestCase
{
    use UsesFakeRestic;

    private ?string $disposableRoot = null;

    private ?string $disposableAppId = null;

    private bool $prefixInitiallyEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('QURABA_BACKUP_TEST_B2') !== '1') {
            self::markTestSkipped('Real B2 tests require explicit QURABA_BACKUP_TEST_B2=1.');
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            self::fail('Opted-in real B2 lifecycle validation requires Linux.');
        }

        $required = ['QURABA_BACKUP_TEST_RESTIC_BINARY', 'QURABA_BACKUP_TEST_B2_ENDPOINT', 'QURABA_BACKUP_TEST_B2_REGION', 'QURABA_BACKUP_TEST_B2_BUCKET', 'QURABA_BACKUP_TEST_B2_KEY_ID', 'QURABA_BACKUP_TEST_B2_APPLICATION_KEY'];

        foreach ($required as $name) {
            if (! is_string(getenv($name)) || getenv($name) === '') {
                self::fail('Opted-in real B2 integration is missing '.implode(', ', $required).'.');
            }
        }

        $bucket = (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET');
        $endpoint = (string) getenv('QURABA_BACKUP_TEST_B2_ENDPOINT');
        if (preg_match('/(?:test|sandbox|disposable)/i', $bucket) !== 1
            || preg_match('/(?:prod|production|live)/i', $bucket) === 1
            || preg_match('~^https://s3\.[a-z0-9-]+\.backblazeb2\.com$~', $endpoint) !== 1
            || $endpoint !== 'https://s3.'.(string) getenv('QURABA_BACKUP_TEST_B2_REGION').'.backblazeb2.com') {
            self::fail('Real B2 integration requires a clearly disposable test bucket and an exact HTTPS B2 S3 endpoint.');
        }

        $this->config()->set('restic.binary', (string) getenv('QURABA_BACKUP_TEST_RESTIC_BINARY'));
        $this->config()->set('quraba-backup.storage.b2.endpoint', (string) getenv('QURABA_BACKUP_TEST_B2_ENDPOINT'));
        $this->config()->set('quraba-backup.storage.b2.bucket', (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET'));
        $this->config()->set('quraba-backup.storage.b2.key_id', (string) getenv('QURABA_BACKUP_TEST_B2_KEY_ID'));
        $this->config()->set('quraba-backup.storage.b2.application_key', (string) getenv('QURABA_BACKUP_TEST_B2_APPLICATION_KEY'));
        $prefix = 'quraba-disposable-test/'.bin2hex(random_bytes(16));
        $this->disposableAppId = (string) Uuid::v7();
        $this->disposableRoot = $prefix.'/'.$this->disposableAppId.'/';
        $this->config()->set('quraba-backup.app_id', $this->disposableAppId);
        $this->config()->set('quraba-backup.storage.b2.prefix', $prefix);
        $this->app->forgetInstance(IdentityResolver::class);
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
        $this->app->forgetInstance(RemoteStorage::class);
        $this->prefixInitiallyEmpty = $this->ownedKeys() === [];
        self::assertTrue($this->prefixInitiallyEmpty, 'The generated disposable prefix must start empty.');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->disposableRoot !== null && $this->prefixInitiallyEmpty) {
                $client = $this->s3();
                foreach ($this->ownedKeys() as $key) {
                    $client->deleteObject(['Bucket' => (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET'), 'Key' => $key]);
                }
                $this->cleanupVersions($client);
                self::assertSame([], $this->ownedKeys(), 'Disposable B2 objects must be removed exactly.');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_disposable_b2_archive_manifest_expiry_and_restic_lifecycle(): void
    {
        $remote = $this->app->make(RemoteStorage::class);
        $objects = $remote->objects();
        $path = $remote->layout()->archivesRoot().'integration-probe.bin';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'disposable-b2-archive');
        rewind($stream);
        try {
            $objects->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }
        self::assertTrue($objects->exists($path));
        self::assertSame(strlen('disposable-b2-archive'), $objects->size($path));
        $read = $objects->readStream($path);
        try {
            self::assertSame(hash('sha256', 'disposable-b2-archive'), hash('sha256', stream_get_contents($read)));
        } finally {
            fclose($read);
        }

        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        $manifests = $this->app->make(ManifestStore::class);
        self::assertFalse($manifests->put($run, '{"probe":true}')->adopted);
        self::assertTrue($manifests->put($run, '{"probe":true}')->adopted);
        $identity = $this->app->make(IdentityResolver::class)->current();
        $record = RetentionTombstone::make($run->uuid, $identity, ['application_archive'], CarbonImmutable::now('UTC'), (string) Uuid::v7());
        $expiry = $this->app->make(RetentionTombstoneStore::class);
        $expiry->put($record);
        self::assertTrue($expiry->find($run->uuid)?->covers('application_archive'));

        $repository = $this->app->make(ResticRepository::class);
        self::assertSame(RepositoryState::Uninitialized, $repository->inspect()->state);
        self::assertSame(RepositoryState::Ready, $repository->initialize()->state);
        $runner = $this->app->make(ResticRunner::class);
        $media = $this->sandbox.'/probe';
        mkdir($media);
        file_put_contents($media.'/a.txt', 'restic-b2-probe');
        $backup = $runner->backup(ResticBackupRequest::make([$media], ['quraba-backup', 'app:'.$this->disposableAppId, 'kind:media'], 'b2-test'))->throwIfFailed();
        $id = $backup->summary()['snapshot_id'] ?? null;
        self::assertIsString($id);
        self::assertSame($id, $repository->snapshots(['app:'.$this->disposableAppId])[0]->id);
        $workspace = $this->app->make(WorkspaceManager::class)->create();
        try {
            $runner->restore($id, $workspace)->throwIfFailed();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace->area(WorkspaceArea::Restore), \FilesystemIterator::SKIP_DOTS));
            $restored = array_values(array_filter(iterator_to_array($files, false), static fn (\SplFileInfo $file): bool => $file->getFilename() === 'a.txt'));
            self::assertCount(1, $restored);
            self::assertSame('restic-b2-probe', file_get_contents($restored[0]->getPathname()));
        } finally {
            $workspace->cleanup();
        }
        $runner->forget([$id])->throwIfFailed();
        self::assertSame([], $repository->snapshots());
        if (getenv('QURABA_BACKUP_TEST_B2_PRUNE') === '1') {
            $runner->prune()->throwIfFailed();
        }
    }

    /** @return list<string> */
    private function ownedKeys(): array
    {
        $prefix = $this->disposableRoot;
        if ($prefix === null || preg_match('~^quraba-disposable-test/[0-9a-f]{32}/[0-9a-f-]{36}/$~', $prefix) !== 1) {
            throw new \LogicException('Refusing B2 listing or cleanup outside a generated disposable application prefix.');
        }
        $keys = [];
        $continuation = null;
        do {
            $options = ['Bucket' => (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET'), 'Prefix' => $prefix];
            if ($continuation !== null) {
                $options['ContinuationToken'] = $continuation;
            }
            $page = $this->s3()->listObjectsV2($options);
            foreach ($page->get('Contents') ?? [] as $item) {
                $key = $item['Key'] ?? null;
                if (! is_string($key) || ! str_starts_with($key, $prefix)) {
                    throw new \LogicException('The B2 test listing escaped its disposable prefix.');
                }
                $keys[] = $key;
            }
            $continuation = $page->get('IsTruncated') === true ? $page->get('NextContinuationToken') : null;
        } while (is_string($continuation) && $continuation !== '');

        return $keys;
    }

    private function s3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => (string) getenv('QURABA_BACKUP_TEST_B2_REGION'),
            'endpoint' => (string) getenv('QURABA_BACKUP_TEST_B2_ENDPOINT'),
            'credentials' => ['key' => (string) getenv('QURABA_BACKUP_TEST_B2_KEY_ID'), 'secret' => (string) getenv('QURABA_BACKUP_TEST_B2_APPLICATION_KEY')],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    private function cleanupVersions(S3Client $client): void
    {
        $prefix = $this->disposableRoot;
        if ($prefix === null || preg_match('~^quraba-disposable-test/[0-9a-f]{32}/[0-9a-f-]{36}/$~', $prefix) !== 1) {
            throw new \LogicException('Refusing B2 version cleanup outside a generated disposable application prefix.');
        }

        $bucket = (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET');
        $keyMarker = null;
        $versionMarker = null;
        do {
            $options = ['Bucket' => $bucket, 'Prefix' => $prefix];
            if ($keyMarker !== null) {
                $options['KeyMarker'] = $keyMarker;
                $options['VersionIdMarker'] = $versionMarker;
            }
            $page = $client->listObjectVersions($options);
            foreach (['Versions', 'DeleteMarkers'] as $family) {
                foreach ($page->get($family) ?? [] as $item) {
                    $key = $item['Key'] ?? null;
                    $version = $item['VersionId'] ?? null;
                    if (! is_string($key) || ! str_starts_with($key, $prefix) || ! is_string($version)) {
                        throw new \LogicException('The B2 version listing escaped its disposable prefix.');
                    }
                    $client->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version]);
                }
            }
            $keyMarker = $page->get('IsTruncated') === true ? $page->get('NextKeyMarker') : null;
            $versionMarker = $page->get('NextVersionIdMarker');
        } while (is_string($keyMarker) && $keyMarker !== '');
    }

    public function test_fresh_prefix_is_reported_uninitialized_not_broken(): void
    {
        $repository = $this->app->make(ResticRepository::class);

        self::assertSame(RepositoryState::Uninitialized, $repository->inspect()->state);

        if (getenv('QURABA_BACKUP_TEST_B2_ALLOW_INIT') === '1') {
            self::assertSame(RepositoryState::Ready, $repository->initialize()->state);
        }
    }

    public function test_wrong_credentials_are_distinguished(): void
    {
        $this->config()->set('quraba-backup.storage.b2.application_key', 'definitely-not-the-right-key');
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);

        self::assertSame(RepositoryState::CredentialsRejected, $this->app->make(ResticRepository::class)->inspect()->state);
    }
}

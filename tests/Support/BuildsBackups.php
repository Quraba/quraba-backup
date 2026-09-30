<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Backup\ApplicationArchiveService;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Backup\BackupReconciler;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Tests\TestCase;

/**
 * Wiring for pipeline tests: SQLite dumper, a media root in the sandbox and
 * the fake (or real) Restic.
 *
 * @mixin TestCase
 */
trait BuildsBackups
{
    protected SqliteTestDumper $dumper;

    protected string $mediaRoot;

    protected function prepareBackupPipeline(): void
    {
        $this->dumper = new SqliteTestDumper($this->app->make('db'));
        $this->app->instance(DatabaseDumper::class, $this->dumper);

        $this->mediaRoot = $this->sandbox.'/media';

        if (! is_dir($this->mediaRoot)) {
            mkdir($this->mediaRoot.'/uploads', 0700, true);
            file_put_contents($this->mediaRoot.'/uploads/a.jpg', 'image-a');
        }

        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot]]);
        $this->refreshBackupServices();
    }

    protected function refreshBackupServices(): void
    {
        foreach ([ArchiveEngine::class, ApplicationArchiveService::class, ArchiveStore::class, MediaRootResolver::class, QuiescenceProvider::class, BackupManager::class, BackupReconciler::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    protected function manager(): BackupManager
    {
        return $this->app->make(BackupManager::class);
    }

    protected function reconciler(): BackupReconciler
    {
        return $this->app->make(BackupReconciler::class);
    }
}

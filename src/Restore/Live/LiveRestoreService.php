<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Contracts\RestoreStepObserver;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Database\DatabaseTarget;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Media\MediaDestination;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\JournalPhase;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\MediaRootMapping;
use Quraba\Backup\Restore\PreparedRestore;
use Quraba\Backup\Restore\ResticReconstructor;
use Quraba\Backup\Restore\RestorePreparation;
use Quraba\Backup\Restore\RestoreSource;
use Quraba\Backup\Restore\RestoreSourceResolver;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/**
 * The LIVE (destructive) restore of one exact backup run.
 *
 *   gate (authorization, no unresolved restore, provable quiescence)
 *   → the complete non-destructive preparation, fresh, never an old dry run
 *   → plan the live targets (database identity + inventory, media roots)
 *   → enter quiescence and prove it
 *   → verified pre-change safety backup (or proven-empty clean host)
 *   → re-verify everything that was validated
 *   ── destructive boundary ──
 *   → database: clear exactly → import → verify → hand the audit row back
 *   → every media root: park the live tree → activate the staged tree → verify
 *   → final verification → COMPLETED
 *
 * ORDER (deliberate): the database is replaced before any media root. The
 * import is the long, non-atomic step and the one most likely to fail; it
 * runs while every media root is still untouched, so its failure leaves one
 * component to recover. Media roots are swapped by renames that take
 * moments and keep the old tree intact next to the new one. The database
 * and the media roots are NOT one transaction, and neither are two media
 * roots: each is journaled on its own.
 *
 * JOURNAL: every step is written to the external restore journal BEFORE it
 * happens. A journal that cannot be written stops the restore before that
 * step. The catalog row is only a mirror.
 *
 * FAILURE: before the boundary the restore FAILS and nothing was changed.
 * After it the restore becomes INDETERMINATE: nothing is rolled back, the
 * safety backup, the parked media and the journal are kept, and the
 * application stays in maintenance mode until an operator decides.
 */
final readonly class LiveRestoreService
{
    public const string COMPLETED_NOTICE = 'Restore completed. The application remains in maintenance mode for operator verification.';

    public function __construct(
        private Repository $config,
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RestoreJournalStore $journals,
        private RestorePreparation $preparation,
        private RestoreSourceResolver $sources,
        private ResticReconstructor $restic,
        private ArchiveStore $archives,
        private QuiescenceProvider $quiescence,
        private SafetyBackupService $safety,
        private DatabaseReplacement $database,
        private MediaStaging $staging,
        private ExactDirectoryReplacement $directories,
        private CleanHostProof $cleanHost,
        private MediaRootResolver $roots,
        private WorkspaceManager $workspaces,
        private LocalCatalog $catalog,
        private RestoreStepObserver $steps,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * What a live restore of this run would replace. Read-only: shown to the
     * operator before the restore is confirmed.
     *
     * @return array<string, mixed>
     */
    public function describe(string $runUuid, RestoreProfile $profile, bool $cleanHost): array
    {
        $source = $this->sources->resolve(Identifiers::assertUuid($runUuid, 'The restore source run UUID'), $profile);
        $database = null;

        if ($profile !== RestoreProfile::Media) {
            try {
                $target = $this->database->target();
                $database = $target->database.' on '.$target->server.' (connection '.$target->connection.')';
            } catch (Throwable $exception) {
                $database = 'NOT PROVEN: '.$this->redactor->redact($exception->getMessage());
            }
        }

        $media = [];

        if ($profile !== RestoreProfile::Database) {
            $names = array_column($source->mediaRoots, 'name');

            foreach ($this->roots->destinations() as $destination) {
                if (in_array($destination->name, $names, true)) {
                    $media[$destination->name] = $destination->path.($destination->exists ? '' : ' (does not exist yet)');
                }
            }
        }

        $unresolved = array_map(static fn (RestoreJournal $journal): string => $journal->restoreUuid(), $this->journals->unresolved());

        return [
            'source_run' => $source->runUuid,
            'source_origin' => $source->origin,
            'profile' => $profile->value,
            'consistency' => $source->consistency,
            'archive_sha256' => $profile === RestoreProfile::Media ? null : $source->archiveSha256,
            'snapshot_id' => $profile === RestoreProfile::Database ? null : $source->snapshotId,
            'repository_id' => $profile === RestoreProfile::Database ? null : $source->repositoryId,
            'db_validation_level' => $profile === RestoreProfile::Media ? null : $this->config->get('quraba-backup.restore.db_validation_level', 'artifact'),
            'live_database' => $database,
            'media_destinations' => $media,
            'safety_backup' => $cleanHost
                ? 'not taken if (and only if) the target is proven empty (--clean-host)'
                : 'a verified '.SafetyBackupService::profileFor($profile)->value.' safety backup is taken first',
            'quiescence' => sprintf('provider %s: %s', $this->quiescence->name(), $this->quiescence->claimsQuiescence() ? 'can prove that writers are stopped' : 'CANNOT prove that writers are stopped (a live restore will be refused)'),
            'maintenance' => 'the application stays in maintenance mode after the restore; bring it up yourself after verification',
            'unresolved_restores' => $unresolved,
        ];
    }

    /**
     * @return array<string, mixed> the restore report (`status`: completed, failed or indeterminate)
     */
    public function run(string $runUuid, RestoreProfile $profile, LiveRestoreAuthorization $authorization): array
    {
        $runUuid = Identifiers::assertUuid($runUuid, 'The restore source run UUID');
        $identity = $this->identities->current();

        // Global write lock + restore lock for the whole destructive lifecycle.
        $locks = $this->coordinator->beginLiveRestore('live restore '.$profile->value);
        $context = new LiveRestoreContext($runUuid, $profile, $authorization->cleanHost, $locks);

        try {
            $this->execute($context, $identity);
        } catch (Throwable $exception) {
            $this->fail($context, $exception);
        } finally {
            $this->finish($context);
            $locks->release();
        }

        return $context->report;
    }

    private function execute(LiveRestoreContext $c, ApplicationIdentity $identity): void
    {
        $this->assertNoUnresolvedRestore();

        if (! $this->quiescence->claimsQuiescence()) {
            throw RestoreFailed::quiescenceUnproven(sprintf('the configured quiescence provider [%s] cannot prove it. Configure quraba-backup.consistency.provider=laravel_maintenance and declare no_background_writers only when queue workers, the scheduler and every other writer are really stopped. There is no best-effort live restore', $this->quiescence->name()));
        }

        $hasAuditTable = $this->catalog->has('quraba_restore_runs');

        if (! $hasAuditTable && ! $c->cleanHost) {
            throw RestoreFailed::catalogUnavailable('the restore audit table does not exist. Run the package migrations; or, when this really is a new empty host, declare it with --clean-host');
        }

        if ($hasAuditTable) {
            $c->audit = RestoreRun::request(RestoreMode::Restore, $c->profile, $c->runUuid);
            $c->audit->markResolving();
        }

        $restoreUuid = $c->audit === null ? (string) Str::uuid7() : $c->audit->uuid;
        $c->restoreUuid = $restoreUuid;
        $c->report['restore_uuid'] = $restoreUuid;
        $workspace = $c->workspace = $this->workspaces->create();

        // The whole non-destructive preparation, fresh.
        $prepared = $this->preparation->prepare(
            $c->runUuid,
            $c->profile,
            $workspace,
            $c->report,
            function (string $stage, ?RestoreSource $source) use ($c, $identity, $restoreUuid): void {
                if ($stage === 'resolved' && $source !== null) {
                    $this->assertImmutableOrigin($source);
                    // The journal exists from the moment the exact source is frozen.
                    $c->journal = $this->journals->create(RestoreJournal::open($restoreUuid, $identity, $c->profile, $source, $c->cleanHost));
                    $c->report['journal'] = $this->journals->directory().'/'.$restoreUuid.'.json';
                    $c->audit?->freezeSource(
                        $c->profile === RestoreProfile::Media ? null : $source->archiveLocator,
                        $c->profile === RestoreProfile::Media ? null : $source->archiveSha256,
                        $c->profile === RestoreProfile::Database ? null : $source->snapshotId,
                    );
                    $c->audit?->mergeMetadata(['source' => $source->identities(), 'origin' => $source->origin, 'clean_host' => $c->cleanHost]);
                } elseif ($stage === 'reconstructing') {
                    $c->audit?->markReconstructing();
                } elseif ($stage === 'validating') {
                    $c->audit?->markValidating();
                }
            },
            /**
             * @param  list<MediaDestination>  $destinations
             * @param  list<string>  $allRoots
             * @return list<MediaStagingArea>
             */
            function (array $destinations, array $allRoots) use ($c, $workspace, $restoreUuid): array {
                $snapshotId = $c->journal?->source()['snapshot_id'] ?? null;

                return $c->areas = $this->staging->plan($destinations, $allRoots, $workspace, $restoreUuid, is_string($snapshotId) ? $snapshotId : '');
            },
        );
        $this->steps->reached('prepared');

        // Plan the live targets. Still nothing is changed.
        $target = null;
        $inventory = null;

        if ($c->profile !== RestoreProfile::Media) {
            $target = $this->database->target();
            $inventory = $this->database->inventory($target);
            $importable = $this->database->assertImportable($target, $prepared->archive['database_dump'] ?? '');
            $c->report['live_database'] = $target->database;
            $c->report['database_import'] = $importable;

            if ($inventory->names(SchemaInventory::EVENT) !== []) {
                $c->warn(sprintf('The live database holds %d scheduled event(s). Exact replacement removes them; they only come back if the backup was made with database.dump_events enabled.', count($inventory->names(SchemaInventory::EVENT))));
            }
            $this->record($c, fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabasePlan([
                ...$target->toArray(),
                'inventory_before' => $inventory->summary(),
                'import' => $importable,
                'validation' => $prepared->validation,
            ]));
        }

        foreach ($prepared->media as $mapping) {
            $this->planRoot($c, $mapping, $restoreUuid);
        }

        $emptyTarget = $c->cleanHost ? $this->cleanHost->prove($c->profile, $target, $inventory, $prepared->media) : null;
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withPhase(JournalPhase::Validated));
        $this->steps->reached('validated');

        // Proven quiescence: no best-effort destructive restore.
        $c->audit?->markQuiescing();
        $session = $c->session = $this->quiescence->enter();

        if ($session->level !== ConsistencyLevel::Quiesced) {
            throw RestoreFailed::quiescenceUnproven(sprintf('provider %s: %s', $session->provider, $session->explanation));
        }

        $quiescence = ['provider' => $session->provider, 'level' => $session->level->value, 'entered_by_package' => $session->enteredHere, 'explanation' => $session->explanation];
        $c->report['quiescence'] = $quiescence;
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withQuiescence($quiescence));
        $this->steps->reached('quiesced');

        // Verified pre-change safety backup — or a proven-empty clean host.
        if ($emptyTarget !== null) {
            $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withSafetyBackupNotRequired($emptyTarget));
            $c->audit?->markSafetyBackupNotRequired($emptyTarget);
        } else {
            $evidence = $this->safety->take($c->profile, $c->locks, $restoreUuid, $identity, function (BackupRun $run) use ($c): void {
                $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withSafetyBackupStarting($run->profile->value, $run->uuid));
                $this->steps->reached('safety_backup.announced');
            });
            $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withSafetyBackupVerified($evidence));
            $c->audit?->markSafetyBackup($this->journal($c)->safetyRunUuid() ?? '');
        }

        $c->report['safety_backup'] = $this->journal($c)->safetyBackup();
        $this->steps->reached('safety_backup.settled');

        // Everything validated must still hold now, immediately before the boundary.
        $this->reverify($c, $prepared, $identity, $target, $inventory);
        $c->audit?->markApplying();
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withPhase(JournalPhase::Applying));
        $this->steps->reached('applying');

        // ── destructive boundary ── database first, then every media root.
        if ($target !== null && $inventory !== null) {
            $this->replaceDatabase($c, $prepared, $target, $inventory);
        }

        foreach ($prepared->media as $mapping) {
            $this->replaceRoot($c, $mapping);
        }

        if ($prepared->media !== []) {
            $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withPhase(JournalPhase::MediaApplied));
        }

        // Final verification of the whole restore.
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withPhase(JournalPhase::Verifying));
        $this->mirror($c);
        $this->steps->reached('verifying');
        $verification = $this->verifyAll($c, $prepared, $identity, $target);
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withVerification($verification));
        $this->steps->reached('verified');
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withTerminal(RestoreJournal::TERMINAL_COMPLETED));

        $this->complete($c);
    }

    /**
     * The database of a full or database restore, replaced exactly.
     */
    private function replaceDatabase(LiveRestoreContext $c, PreparedRestore $prepared, DatabaseTarget $target, SchemaInventory $inventory): void
    {
        $dump = $prepared->archive['database_dump'] ?? throw RestoreFailed::databaseApplyFailed('no validated SQL dump is available');
        $this->steps->reached('db.before_clear');

        // Durable BEFORE the first DROP: this record is the destructive boundary.
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabaseState('clear_starting'));
        $c->report['destructive_boundary_crossed'] = true;
        $this->mirror($c);
        $this->steps->reached('db.clear_starting');

        $dropped = 0;
        $this->database->clear($target, $inventory, function (string $type) use (&$dropped): void {
            $dropped++;
            $this->steps->reached('db.object_dropped', ['index' => $dropped, 'type' => $type]);
        });
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabaseState('cleared', ['objects_dropped' => $dropped]));
        $this->steps->reached('db.cleared');

        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabaseState('import_starting'));
        $this->steps->reached('db.import_starting');
        $this->database->import($target, $dump, $this->workspace($c));
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabaseState('import_completed'));
        $this->steps->reached('db.import_completed');

        // A clean client exit proves nothing by itself.
        $verification = $this->database->verify($target, $dump, $prepared->databaseMetadata(), $prepared->validation['scratch_schema_fingerprint'] ?? null);
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withDatabaseState('verified', ['verification' => $verification]));
        $this->steps->reached('db.verified');

        // The imported catalog is an OLD copy: it cannot contain this restore.
        // The journal hands the audit row back; stale restored rows are not trusted.
        $handback = $this->mirror($c);
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withAuditHandback($handback));

        if ($handback !== 'synced') {
            $c->warn('The restore audit row could not be handed back into the restored catalog ('.$handback.'); run "php artisan quraba:backup:restore-reconcile --restore='.$c->restoreUuid.'" once the catalog tables exist.');
        }

        $this->steps->reached('db.audit_restored');
    }

    private function planRoot(LiveRestoreContext $c, MediaRootMapping $mapping, string $restoreUuid): void
    {
        $live = $this->directories->identity($mapping->liveDestination);

        if (($live !== null) !== $mapping->liveExists || (! $mapping->liveExists && (file_exists($mapping->liveDestination) || is_link($mapping->liveDestination)))) {
            throw RestoreFailed::mappingFailed(sprintf('the live destination of media root [%s] changed while the restore was prepared', $mapping->name));
        }

        $plan = [
            'live' => $mapping->liveDestination,
            'live_existed' => $live !== null,
            'live_identity' => $live,
            'staged' => $mapping->workspaceSubtree,
            'staged_identity' => $this->directories->identity($mapping->workspaceSubtree),
            'staged_tree' => $this->directories->fingerprint($mapping->workspaceSubtree, $mapping->liveDestination, $mapping->allowSymlinks),
            'parked' => $live === null ? null : $this->directories->parkedPath($mapping->liveDestination, $restoreUuid),
            'allow_symlinks' => $mapping->allowSymlinks,
        ];

        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootPlan($mapping->name, $plan));
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootState($mapping->name, 'staged'));
    }

    /**
     * One media root, replaced exactly by two renames. Never a copy.
     */
    private function replaceRoot(LiveRestoreContext $c, MediaRootMapping $mapping): void
    {
        $name = $mapping->name;
        $root = $this->journal($c)->media()[$name] ?? throw RestoreFailed::mediaApplyFailed('a media root is missing from the journal');
        $live = $mapping->liveDestination;
        $staged = $mapping->workspaceSubtree;
        $parked = is_string($root['parked'] ?? null) ? $root['parked'] : null;
        $this->steps->reached('media.before_swap', ['root' => $name]);

        // Proofs repeated for THIS root immediately before its own boundary.
        if ($this->directories->identity($staged) !== ($root['staged_identity'] ?? null)) {
            throw RestoreFailed::mediaApplyFailed(sprintf('the staged tree of media root [%s] is no longer the tree that was validated', $name));
        }

        if ($this->directories->identity($live) !== ($root['live_identity'] ?? null)) {
            throw RestoreFailed::mediaApplyFailed(sprintf('the live media root [%s] is no longer the directory that was inspected', $name));
        }

        if (! $this->staging->sameFilesystem(dirname($staged), dirname($live))) {
            throw RestoreFailed::mediaApplyFailed(sprintf('the staged tree of media root [%s] is no longer on the filesystem of its destination; it will not be copied', $name));
        }

        // Durable BEFORE the live tree moves: for a media-only restore this
        // record is the destructive boundary.
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootState($name, 'swap_starting'));
        $c->report['destructive_boundary_crossed'] = true;
        $this->mirror($c);
        $this->steps->reached('media.swap_starting', ['root' => $name]);

        if ($parked !== null) {
            $this->directories->park($live, $parked);
        }

        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootState($name, 'parked'));
        $this->steps->reached('media.parked', ['root' => $name]);

        $this->directories->activate($staged, $live);
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootState($name, 'activated'));
        $this->steps->reached('media.activated', ['root' => $name]);

        $verification = $this->verifyRoot($root, $mapping);
        $this->record($c, static fn (RestoreJournal $journal): RestoreJournal => $journal->withRootState($name, 'verified', ['verification' => $verification]));
        $this->steps->reached('media.verified', ['root' => $name]);
    }

    /**
     * @param  array<string, mixed>  $root  the journaled plan of the root
     * @return array<string, mixed>
     */
    private function verifyRoot(array $root, MediaRootMapping $mapping): array
    {
        $tree = $root['staged_tree'] ?? null;
        $identity = $root['staged_identity'] ?? null;

        if (! is_array($tree) || ! is_int($tree['files'] ?? null) || ! is_int($tree['directories'] ?? null) || ! is_int($tree['links'] ?? null) || ! is_int($tree['bytes'] ?? null) || ! is_string($tree['sha256'] ?? null)) {
            throw RestoreFailed::mediaVerificationFailed('the journal holds no staged tree description');
        }

        return $this->directories->verify(
            $mapping->liveDestination,
            ['files' => $tree['files'], 'directories' => $tree['directories'], 'links' => $tree['links'], 'bytes' => $tree['bytes'], 'sha256' => $tree['sha256']],
            is_array($identity) && is_int($identity['dev'] ?? null) && is_int($identity['ino'] ?? null) ? ['dev' => $identity['dev'], 'ino' => $identity['ino']] : null,
            $mapping->allowSymlinks,
        );
    }

    /**
     * Nothing validated earlier is trusted across quiescence and the safety
     * backup: the source, the repository, the targets and the staged data
     * are proven again right before the destructive boundary.
     */
    private function reverify(LiveRestoreContext $c, PreparedRestore $prepared, ApplicationIdentity $identity, ?DatabaseTarget $target, ?SchemaInventory $inventory): void
    {
        $this->assertSourceFrozen($c, $prepared);

        if ($c->profile !== RestoreProfile::Database) {
            $this->restic->assertRepository((string) $prepared->source->repositoryId);
            $this->restic->assertSnapshot($prepared->source, $identity, listed: true);
        }

        if ($c->profile !== RestoreProfile::Media && $prepared->source->archiveLocator !== null && $prepared->source->archiveBytes !== null
            && $this->archives->sample($prepared->source->archiveLocator, $prepared->source->archiveBytes) !== 'present') {
            throw RestoreFailed::sourceChanged('the exact source archive is no longer present with its recorded size');
        }

        if ($target !== null && $inventory !== null) {
            $dump = $prepared->archive['database_dump'] ?? '';

            // The dump that will be imported must be, byte for byte, the dump
            // that was extracted from the verified archive and validated.
            if (@filesize($dump) !== ($prepared->validation['dump_bytes'] ?? null) || @hash_file('sha256', $dump) !== ($prepared->archive['database_dump_sha256'] ?? null)) {
                throw RestoreFailed::sourceChanged('the validated SQL dump changed in the private workspace');
            }

            if ($this->database->inventory($target)->fingerprint() !== $inventory->fingerprint()) {
                throw RestoreFailed::databaseTargetUnsafe('the live schema changed after it was inventoried; something is still writing to the database');
            }
        }

        foreach ($prepared->media as $mapping) {
            $root = $this->journal($c)->media()[$mapping->name] ?? [];

            if ($this->directories->fingerprint($mapping->workspaceSubtree, $mapping->liveDestination, $mapping->allowSymlinks) !== ($root['staged_tree'] ?? null)) {
                throw RestoreFailed::mappingFailed(sprintf('the staged tree of media root [%s] is incomplete or changed since it was validated', $mapping->name));
            }

            if ($this->directories->identity($mapping->liveDestination) !== ($root['live_identity'] ?? null)) {
                throw RestoreFailed::mappingFailed(sprintf('the live media root [%s] changed since it was inspected', $mapping->name));
            }
        }

        if ($c->cleanHost) {
            $this->cleanHost->prove($c->profile, $target, $target === null ? null : $this->database->inventory($target), $prepared->media);
        } elseif (! $this->journal($c)->safetyVerified()) {
            throw RestoreFailed::safetyBackupFailed('the journal holds no verified safety backup');
        }

        $c->session?->assertStillQuiesced();
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyAll(LiveRestoreContext $c, PreparedRestore $prepared, ApplicationIdentity $identity, ?DatabaseTarget $target): array
    {
        $journal = $this->journal($c);
        $verification = ['database' => null, 'media' => [], 'source_frozen' => true, 'repository_id' => null];

        if ($target !== null) {
            if ($journal->databaseState() !== 'verified') {
                throw RestoreFailed::databaseVerificationFailed('the journal does not record a verified database');
            }

            $dump = $prepared->archive['database_dump'] ?? '';
            $again = $this->database->verify($target, $dump, $prepared->databaseMetadata(), $prepared->validation['scratch_schema_fingerprint'] ?? null);
            $recorded = $journal->database()['verification'] ?? null;

            if (! is_array($recorded) || ($recorded['schema_fingerprint'] ?? null) !== $again['schema_fingerprint']) {
                throw RestoreFailed::databaseVerificationFailed('the database schema changed after it was verified');
            }

            $verification['database'] = $again;
        }

        $media = [];

        foreach ($prepared->media as $mapping) {
            if ($journal->rootState($mapping->name) !== 'verified') {
                throw RestoreFailed::mediaVerificationFailed(sprintf('the journal does not record media root [%s] as verified', $mapping->name));
            }

            $media[$mapping->name] = $this->verifyRoot($journal->media()[$mapping->name], $mapping);
        }

        $verification['media'] = $media;
        $this->assertSourceFrozen($c, $prepared);

        if ($c->profile !== RestoreProfile::Database) {
            $this->restic->assertRepository((string) $prepared->source->repositoryId);
            $this->restic->assertSnapshot($prepared->source, $identity, listed: true);
            $verification['repository_id'] = $prepared->source->repositoryId;
        }

        $c->session?->assertStillQuiesced();

        return $verification;
    }

    /**
     * The immutable source must still be exactly the one that was frozen.
     */
    private function assertSourceFrozen(LiveRestoreContext $c, PreparedRestore $prepared): void
    {
        try {
            $current = $this->sources->resolve($c->runUuid, $c->profile);
        } catch (RestoreFailed $exception) {
            throw RestoreFailed::sourceChanged($exception->getMessage());
        }

        foreach ($prepared->source->identities() as $field => $value) {
            // The catalog's own view may change (a restored catalog is older);
            // the identities of the immutable source may not.
            if ($field !== 'manifest_schema' && $value !== $current->identities()[$field]) {
                throw RestoreFailed::sourceChanged($field.' differs');
            }
        }

        $this->assertImmutableOrigin($current);
    }

    private function assertImmutableOrigin(RestoreSource $source): void
    {
        if (! str_contains($source->origin, 'remote')) {
            throw RestoreFailed::sourceUnavailable('a live restore requires the immutable remote manifest of the source run; the local catalog alone is not enough');
        }
    }

    private function assertNoUnresolvedRestore(): void
    {
        $all = $this->journals->all();

        if ($all['unreadable'] !== []) {
            throw RestoreFailed::unresolvedRestore(sprintf('%d restore journal file(s) cannot be read (%s) in %s. They may describe a restore that changed the application; inspect them before any further live restore', count($all['unreadable']), implode(', ', $all['unreadable']), $this->journals->directory()));
        }

        foreach ($all['journals'] as $journal) {
            if ($journal->isUnresolved()) {
                throw RestoreFailed::unresolvedRestore(sprintf(
                    'restore %s (source run %s, safety backup %s) stopped in phase %s%s. Run "php artisan quraba:backup:restore-reconcile --restore=%s" and follow its guidance; no other live restore may start until it is resolved',
                    $journal->restoreUuid(),
                    $journal->sourceRunUuid(),
                    $journal->safetyRunUuid() ?? 'none',
                    $journal->phase()->value,
                    $journal->crossedDestructiveBoundary() ? ' AFTER its destructive boundary' : ' before its destructive boundary',
                    $journal->restoreUuid(),
                ));
            }
        }
    }

    /**
     * Writes the next journal version. The record is durable before the
     * caller performs the step it announces; if it cannot be written the
     * exception stops the restore before that step.
     *
     * @param  callable(RestoreJournal): RestoreJournal  $change
     */
    private function record(LiveRestoreContext $c, callable $change): void
    {
        $c->journal = $this->journals->save($change($this->journal($c)));
    }

    private function journal(LiveRestoreContext $c): RestoreJournal
    {
        return $c->journal ?? throw RestoreFailed::journalFailed('no journal was opened for this restore');
    }

    private function workspace(LiveRestoreContext $c): OperationWorkspace
    {
        return $c->workspace ?? throw RestoreFailed::reconstructionFailed('the restore workspace is gone');
    }

    /**
     * Mirrors the journal into the catalog row. Past the destructive
     * boundary this is best effort: the journal is the authority and the
     * catalog may be in the middle of being replaced.
     */
    private function mirror(LiveRestoreContext $c): string
    {
        if ($c->journal === null) {
            return 'no_journal';
        }

        if (! $this->catalog->has('quraba_restore_runs')) {
            return 'no_catalog_table';
        }

        try {
            $c->audit = RestoreRun::syncFromJournal($c->journal);

            return 'synced';
        } catch (Throwable $exception) {
            $this->logger->warning('Quraba Backup could not mirror the restore journal into the catalog.', ['restore_uuid' => $c->restoreUuid, 'error' => $this->redactor->redact($exception->getMessage())]);

            return 'failed';
        }
    }

    private function complete(LiveRestoreContext $c): void
    {
        $journal = $this->journal($c);
        $c->report['ok'] = true;
        $c->report['status'] = 'completed';
        $c->report['notice'] = self::COMPLETED_NOTICE;
        $c->report['safety_backup'] = $journal->safetyBackup();

        if ($this->mirror($c) !== 'synced') {
            $c->warn('The restore audit row is not in the catalog; the restore journal is the record of this restore.');
        }

        // The pin of the safety backup becomes the configured safety window.
        $safetyUuid = $journal->safetyRunUuid();

        try {
            $safety = $safetyUuid !== null && $this->catalog->has('quraba_backup_runs') ? BackupRun::query()->where('uuid', $safetyUuid)->first() : null;
            $settled = $journal->settledAt();

            if ($safety !== null && $settled !== null) {
                $safety->pin($settled->addDays($this->safetyDays()), 'Pre-change safety backup of completed restore '.$journal->restoreUuid().'.');
            }
        } catch (Throwable $exception) {
            $c->warn('The safety backup pin could not be updated: '.$this->redactor->redact($exception->getMessage()));
        }

        $parked = array_filter(array_map(static fn (array $root): mixed => $root['parked'] ?? null, $journal->media()), is_string(...));
        $followUp = [];

        if ($c->profile !== RestoreProfile::Media) {
            $followUp[] = 'php artisan quraba:backup:reconcile   (the restored catalog is older than this host\'s backups)';
            $followUp[] = 'php artisan quraba:backup:catalog:rebuild --apply   (re-adopts backups made after the restored one, including the safety backup)';
        }

        if ($parked !== []) {
            $followUp[] = 'php artisan quraba:backup:restore-reconcile --restore='.$journal->restoreUuid().' --cleanup-parked   (after you verified the application; removes the parked pre-restore media)';
        }

        $followUp[] = 'php artisan up   (only after you verified the restored application)';
        $c->report['follow_up'] = $followUp;
        $c->report['parked_media'] = array_values($parked);

        if ((bool) $this->config->get('quraba-backup.restore.auto_up', false) && $c->session !== null && $c->session->enteredHere) {
            try {
                $c->session->leave();
                $c->report['notice'] = 'Restore completed. The application was brought back up because restore.auto_up is enabled.';
            } catch (Throwable $exception) {
                $c->warn('Leaving maintenance mode failed: '.$this->redactor->redact($exception->getMessage()));
            }
        }

        $this->logger->notice('Quraba Backup live restore completed.', ['restore_uuid' => $journal->restoreUuid(), 'source_run_uuid' => $journal->sourceRunUuid(), 'profile' => $c->profile->value]);
    }

    /**
     * Before the boundary: FAILED, nothing changed. After it: INDETERMINATE —
     * no rollback, no repeated SQL, maintenance stays on, everything is kept.
     */
    private function fail(LiveRestoreContext $c, Throwable $exception): void
    {
        $failure = FailureDetails::fromThrowable($exception, 'restore.live', $this->redactor);
        $c->report['ok'] = false;
        $c->report['error'] = $failure->toArray();

        if ($c->report['blockers'] === []) {
            $c->report['blockers'] = [$failure->message];
        }

        // The journal on disk decides, not what this process believes it wrote.
        $journal = $c->journal;

        if ($c->restoreUuid !== null) {
            try {
                $journal = $this->journals->find($c->restoreUuid) ?? $journal;
            } catch (Throwable) {
                // An unreadable journal is handled below as "cannot be recorded".
            }
        }

        if ($journal === null) {
            $this->failAudit($c, $failure);
            $c->report['status'] = 'failed';

            return;
        }

        $crossed = $journal->crossedDestructiveBoundary();
        $c->report['destructive_boundary_crossed'] = $crossed;
        $c->report['status'] = $crossed ? 'indeterminate' : 'failed';

        try {
            $c->journal = $journal->terminalState() === null
                ? $this->journals->save($journal->withTerminal($crossed ? RestoreJournal::TERMINAL_INDETERMINATE : RestoreJournal::TERMINAL_FAILED, $failure))
                : $journal;
        } catch (Throwable $journalFailure) {
            $c->journal = $journal;
            $c->warn('The restore journal could not record the outcome: '.$this->redactor->redact($journalFailure->getMessage()).' The journal stays unresolved and blocks further live restores until it is reconciled.');
            $this->logger->critical('Quraba Backup could not write the terminal state of a restore journal.', ['restore_uuid' => $c->restoreUuid]);
        }

        if (! $crossed) {
            $this->failAudit($c, $failure);
            $c->report['notice'] = 'The restore failed before its destructive boundary. Nothing was changed in the live application.';
            $this->restorePriorMaintenanceState($c);

            return;
        }

        $this->mirror($c);
        $c->report['notice'] = 'The restore stopped AFTER its destructive boundary. The application state is INDETERMINATE and it remains in maintenance mode. Nothing was rolled back: the safety backup, the parked media and the journal are preserved.';
        $c->report['safety_backup'] = $journal->safetyBackup();
        $c->report['parked_media'] = array_values(array_filter(array_map(static fn (array $root): mixed => $root['parked'] ?? null, $journal->media()), is_string(...)));
        $c->report['follow_up'] = [
            'php artisan quraba:backup:restore-reconcile --restore='.$journal->restoreUuid().'   (inspects the journal and the physical state; never repeats SQL, never rolls back)',
        ];
        $this->logger->critical('Quraba Backup live restore is INDETERMINATE after its destructive boundary.', ['restore_uuid' => $journal->restoreUuid(), 'phase' => $journal->phase()->value, 'code' => $failure->code]);
    }

    private function failAudit(LiveRestoreContext $c, FailureDetails $failure): void
    {
        try {
            if ($c->audit !== null && ! $c->audit->refresh()->status->isTerminal()) {
                $c->audit->markFailed($failure);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Quraba Backup could not record a failed restore in the catalog.', ['restore_uuid' => $c->restoreUuid, 'error' => $this->redactor->redact($exception->getMessage())]);
        }
    }

    /**
     * A restore that changed nothing leaves maintenance mode exactly as it
     * found it: an application that was already down stays down.
     */
    private function restorePriorMaintenanceState(LiveRestoreContext $c): void
    {
        if ($c->session === null || ! $c->session->enteredHere) {
            return;
        }

        try {
            $c->session->leave();
        } catch (Throwable $exception) {
            $c->warn('The application could not be brought back up after the failed restore; it may still be in maintenance mode: '.$this->redactor->redact($exception->getMessage()));
        }
    }

    private function finish(LiveRestoreContext $c): void
    {
        $status = $c->report['status'];

        // After the boundary everything is evidence: keep it for the operator.
        if ($status === 'indeterminate') {
            $c->report['workspace_kept'] = $c->workspace?->root();

            return;
        }

        foreach ($c->areas as $area) {
            $errors = $status === 'completed' ? ($this->staging->release($area) ? [] : ['leftover staging directory']) : $this->staging->discard($area);

            if ($errors !== []) {
                $c->warn('A private staging directory could not be removed: '.(string) $area->owned);
            }
        }

        if ($c->workspace !== null && ! $c->workspace->cleanup()->succeeded()) {
            $c->warn('The private restore workspace could not be removed completely; see quraba:backup:workspace:list.');
        }
    }

    private function safetyDays(): int
    {
        $days = $this->config->get('quraba-backup.retention.safety_days', 30);

        return is_int($days) && $days >= 1 ? $days : 30;
    }
}

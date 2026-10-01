<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Workspace\WorkspaceDeleter;
use Throwable;

/**
 * Decides what an interrupted or indeterminate LIVE restore actually did,
 * from the external journal and the physical state of the application.
 *
 * It reads: the journal; the current database identity, inventory and
 * schema fingerprint; every media root named by the journal (live, staged,
 * parked); the remote state of the safety backup; the audit row.
 *
 * Outcomes:
 *  - COMPLETED  — every component the restore planned is positively proven
 *                 to be in its final state;
 *  - FAILED     — only when the journal shows the destructive boundary was
 *                 never crossed (that record is written before any mutation);
 *  - INDETERMINATE — anything else. The command explains what it found and
 *                 what the operator can do;
 *  - ABANDONED  — only on an explicit operator decision.
 *
 * In every case the catalog row is repaired from the journal. It never
 * repeats SQL, never renames a media root, never restores the safety backup
 * and never changes maintenance mode. The only data it can remove is the
 * parked pre-restore media of a COMPLETED restore, on explicit request.
 */
final readonly class RestoreReconciler
{
    public const string ABANDON_PHRASE = 'ABANDON_RESTORE';

    public function __construct(
        private Repository $config,
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RestoreJournalStore $journals,
        private DatabaseReplacement $database,
        private ExactDirectoryReplacement $directories,
        private SafetyBackupInspector $safety,
        private LocalCatalog $catalog,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Read-only overview of every restore journal on this host: enough to
     * identify an unresolved restore, never a secret.
     *
     * @return array{journals: list<array<string, mixed>>, unreadable: list<string>, unresolved: int, directory: string}
     */
    public function overview(): array
    {
        $all = $this->journals->all();

        return [
            'journals' => array_map(static fn (RestoreJournal $journal): array => $journal->summary(), $all['journals']),
            'unreadable' => $all['unreadable'],
            'unresolved' => count(array_filter($all['journals'], static fn (RestoreJournal $journal): bool => $journal->isUnresolved())),
            'directory' => $this->journals->directory(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcile(string $restoreUuid, bool $abandon = false, bool $cleanupParked = false): array
    {
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $identity = $this->identities->current();

        // The same locks as a live restore: a running restore is never "reconciled".
        $locks = $this->coordinator->beginLiveRestore('restore reconciliation');

        try {
            $journal = $this->journals->find($restoreUuid)
                ?? throw RestoreFailed::journalFailed(sprintf('no restore journal exists for %s in %s; without it the restore cannot be reconciled', $restoreUuid, $this->journals->directory()));

            if ($journal->appId() !== $identity->appId || $journal->environment() !== $identity->environment) {
                throw RestoreFailed::journalFailed('the journal belongs to another application or environment');
            }

            $before = $journal->summary();
            $evidence = $this->evidence($journal, $identity);
            $guidance = [];
            $outcome = $journal->outcome();

            if ($outcome === null) {
                [$journal, $outcome, $guidance] = $this->decide($journal, $evidence, $abandon);
            } elseif ($journal->terminalState() === RestoreJournal::TERMINAL_FAILED && $journal->resolution() === null) {
                // A handled failure before the boundary: acknowledging it
                // starts the retention window of its safety backup.
                $journal = $this->journals->save($journal->withResolution(RestoreJournal::TERMINAL_FAILED, ['reason' => 'failed before the destructive boundary; acknowledged by reconciliation', 'evidence' => $evidence]));
                $guidance[] = 'The restore failed before it changed anything. If the application is still in maintenance mode because of it, bring it up with "php artisan up".';
            }

            $report = [
                'ok' => $outcome !== null,
                'restore_uuid' => $restoreUuid,
                'outcome' => $outcome ?? 'indeterminate',
                'before' => $before,
                'journal' => $journal->summary(),
                'evidence' => $evidence,
                'audit' => $this->repairAudit($journal),
                'safety_backup_pin' => $this->settleSafetyPin($journal),
                'parked_removed' => [],
                'guidance' => $guidance,
                'notice' => 'Reconciliation never repeats SQL, never moves media, never restores a safety backup and never changes maintenance mode.',
            ];

            if ($cleanupParked) {
                if ($outcome !== RestoreJournal::TERMINAL_COMPLETED) {
                    throw RestoreFailed::journalFailed('parked pre-restore media is only removed after the restore is COMPLETED; it is the operator\'s way back otherwise');
                }

                [$journal, $report['parked_removed']] = $this->removeParked($journal);
                $report['journal'] = $journal->summary();
            }

            $this->logger->notice('Quraba Backup restore reconciliation.', ['restore_uuid' => $restoreUuid, 'outcome' => $report['outcome']]);

            return $report;
        } finally {
            $locks->release();
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{0: RestoreJournal, 1: ?string, 2: list<string>}
     */
    private function decide(RestoreJournal $journal, array $evidence, bool $abandon): array
    {
        if (! $journal->crossedDestructiveBoundary()) {
            // The boundary record is written BEFORE the first mutation, so its
            // absence proves that nothing was changed.
            $journal = $this->journals->save($journal->withResolution(RestoreJournal::TERMINAL_FAILED, ['reason' => 'the journal records no destructive boundary: no mutation occurred', 'evidence' => $evidence]));

            return [$journal, RestoreJournal::TERMINAL_FAILED, [
                'The restore stopped in phase '.$journal->phase()->value.' before it changed anything.',
                'If it left the application in maintenance mode, bring it up with "php artisan up" after checking.',
            ]];
        }

        $database = is_array($evidence['database'] ?? null) ? $evidence['database'] : null;
        $media = is_array($evidence['media'] ?? null) ? $evidence['media'] : [];
        $proven = ($database === null || ($database['proven_final'] ?? false) === true);

        foreach ($media as $root) {
            $proven = $proven && is_array($root) && ($root['proven_final'] ?? false) === true;
        }

        if ($proven) {
            $journal = $this->journals->save($journal->withResolution(RestoreJournal::TERMINAL_COMPLETED, ['reason' => 'every planned component is positively proven to be in its final restored state', 'evidence' => $evidence]));

            return [$journal, RestoreJournal::TERMINAL_COMPLETED, ['Every component is proven restored. Verify the application, then bring it up with "php artisan up".']];
        }

        if ($abandon) {
            $journal = $this->journals->save($journal->withResolution(RestoreJournal::RESOLUTION_ABANDONED, ['reason' => 'explicit operator decision; the final state was not proven', 'evidence' => $evidence]));

            return [$journal, RestoreJournal::RESOLUTION_ABANDONED, [
                'The restore is closed as ABANDONED. The application state is whatever the evidence above describes; nothing was repaired.',
                'A new live restore is now possible. Parked media and the safety backup were not touched.',
            ]];
        }

        return [$journal, null, $this->guidance($journal, $evidence)];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private function guidance(RestoreJournal $journal, array $evidence): array
    {
        $uuid = $journal->restoreUuid();
        $safety = $journal->safetyRunUuid();
        $lines = ['The restore crossed its destructive boundary and its final state cannot be proven. Keep the application in maintenance mode.'];
        $database = is_array($evidence['database'] ?? null) ? $evidence['database'] : null;

        if ($database !== null) {
            $lines[] = sprintf('Database: journal state "%s"; %s.', (string) $journal->databaseState(), is_string($database['finding'] ?? null) ? $database['finding'] : 'not proven');
        }

        foreach (is_array($evidence['media'] ?? null) ? $evidence['media'] : [] as $name => $root) {
            if (is_array($root)) {
                $lines[] = sprintf('Media root %s: journal state "%s"; %s.', (string) $name, (string) $journal->rootState((string) $name), is_string($root['finding'] ?? null) ? $root['finding'] : 'not proven');
            }
        }

        $lines[] = $safety === null
            ? 'No safety backup was taken (declared clean host).'
            : sprintf('The pre-change state is preserved in safety backup %s (never removed while this restore is unresolved).', $safety);
        $lines[] = sprintf('To continue: decide, then close this restore with "php artisan quraba:backup:restore-reconcile --restore=%s --abandon --confirm=%s". Afterwards either repeat the restore of source run %s, or restore the safety backup%s.', $uuid, self::ABANDON_PHRASE, $journal->sourceRunUuid(), $safety === null ? '' : ' '.$safety);
        $lines[] = 'Nothing is rolled back automatically, and parked media is never deleted automatically.';

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(RestoreJournal $journal, ApplicationIdentity $identity): array
    {
        $safetyUuid = $journal->safetyRunUuid();

        return [
            'inspected_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'phase' => $journal->phase()->value,
            'destructive_boundary_crossed' => $journal->crossedDestructiveBoundary(),
            'database' => $this->databaseEvidence($journal),
            'media' => $this->mediaEvidence($journal),
            'safety_backup' => $safetyUuid === null
                ? ['requirement' => $journal->safetyBackup()['requirement'] ?? null, 'state' => 'not_taken']
                : ['run_uuid' => $safetyUuid, 'journal_status' => $journal->safetyBackup()['status'] ?? null, ...$this->safety->inspect($safetyUuid, $identity)],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function databaseEvidence(RestoreJournal $journal): ?array
    {
        $planned = $journal->database();

        if ($planned === null) {
            return null;
        }

        $state = (string) $journal->databaseState();
        $evidence = ['journal_state' => $state, 'proven_final' => false];

        try {
            $target = $this->database->target();

            if ($target->connection !== ($planned['connection'] ?? null) || $target->database !== ($planned['name'] ?? null) || $target->server !== ($planned['server'] ?? null)) {
                return [...$evidence, 'finding' => 'the configured live database is no longer the one this restore targeted'];
            }

            $inventory = $this->database->inventory($target);
            $fingerprint = $this->database->fingerprint($target);
        } catch (Throwable $exception) {
            return [...$evidence, 'finding' => 'the live database could not be inspected: '.$this->redactor->redact($exception->getMessage())];
        }

        $tables = count($inventory->names(SchemaInventory::TABLE));
        $evidence = [...$evidence, 'objects' => $inventory->count(), 'base_tables' => $tables, 'schema_fingerprint' => $fingerprint];
        $verification = is_array($planned['verification'] ?? null) ? $planned['verification'] : [];
        $validation = is_array($planned['validation'] ?? null) ? $planned['validation'] : [];

        if ($state === 'verified') {
            $matches = ($verification['schema_fingerprint'] ?? null) === $fingerprint && ($verification['base_tables'] ?? null) === $tables;

            return [...$evidence, 'proven_final' => $matches, 'finding' => $matches ? 'the database is exactly the schema this restore verified' : 'the database changed after this restore verified it'];
        }

        if ($state === 'import_completed') {
            // The client finished; the schema must also equal a fingerprint the
            // restore validated BEFORE it changed anything.
            $expected = array_filter([$validation['scratch_schema_fingerprint'] ?? null, $validation['live_schema_fingerprint'] ?? null], is_string(...));
            $matches = in_array($fingerprint, $expected, true) && ($validation['dump_tables'] ?? null) === $tables;

            return [...$evidence, 'proven_final' => $matches, 'finding' => $matches ? 'the import finished and the schema equals the validated backup schema' : 'the import finished but the schema cannot be proven equal to the backup'];
        }

        $finding = match ($state) {
            'pending' => 'the database was not touched by this restore',
            'clear_starting' => $inventory->isEmpty() ? 'the database is empty: clearing finished but was not recorded' : 'clearing started; the database may be partially cleared',
            'cleared' => $inventory->isEmpty() ? 'the database is empty: it was cleared and the import never started' : 'the database was cleared, yet it now holds objects',
            default => 'the import started and its completion was never recorded; the data may be incomplete',
        };

        return [...$evidence, 'finding' => $finding];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mediaEvidence(RestoreJournal $journal): array
    {
        $evidence = [];

        foreach ($journal->media() as $name => $root) {
            $live = is_string($root['live'] ?? null) ? $root['live'] : '';
            $staged = is_string($root['staged'] ?? null) ? $root['staged'] : '';
            $parked = is_string($root['parked'] ?? null) ? $root['parked'] : null;
            $state = (string) $journal->rootState($name);
            $liveIs = 'absent';

            try {
                if (is_dir($live) && ! is_link($live)) {
                    // The directory itself (device + inode) says which tree
                    // it is; its description says whether it is unchanged.
                    $identity = $this->directories->identity($live);
                    $unchanged = $this->directories->fingerprint($live, $live, true) === ($root['staged_tree'] ?? null);
                    $knowsInodes = ($identity['ino'] ?? 0) !== 0;

                    $liveIs = match (true) {
                        $knowsInodes && $identity === ($root['live_identity'] ?? null) => 'original_tree',
                        $knowsInodes && $identity !== ($root['staged_identity'] ?? null) => 'different_tree',
                        default => $unchanged ? 'restored_tree' : 'different_tree',
                    };
                } elseif (file_exists($live) || is_link($live)) {
                    $liveIs = 'not_a_directory';
                }
            } catch (Throwable) {
                $liveIs = 'unreadable';
            }

            $parkedExists = $parked !== null && is_dir($parked) && ! is_link($parked);
            $proven = $liveIs === 'restored_tree' && in_array($state, ['activated', 'verified'], true);

            $evidence[$name] = [
                'journal_state' => $state,
                'live' => $live,
                'live_is' => $liveIs,
                'staged_exists' => $staged !== '' && is_dir($staged),
                'parked' => $parked,
                'parked_exists' => $parkedExists,
                'proven_final' => $proven,
                'finding' => match (true) {
                    $proven => 'the live root is exactly the staged restored tree',
                    $liveIs === 'original_tree' => 'the live root is still the original directory; it was not replaced',
                    $liveIs === 'absent' && $parkedExists => 'the original tree is parked and nothing is at the live path (the restored tree was not activated)',
                    $liveIs === 'restored_tree' => 'the live root holds the restored tree but the journal never recorded its activation',
                    default => 'the live root is '.str_replace('_', ' ', $liveIs),
                },
            ];
        }

        return $evidence;
    }

    private function repairAudit(RestoreJournal $journal): string
    {
        if (! $this->catalog->has('quraba_restore_runs')) {
            return 'unavailable_no_catalog_table';
        }

        try {
            $existing = RestoreRun::query()->where('uuid', $journal->restoreUuid())->first();
            $before = $existing?->status->value;
            $synced = RestoreRun::syncFromJournal($journal);

            return $existing === null ? 'recreated' : ($before === $synced->status->value ? 'consistent' : 'repaired');
        } catch (Throwable $exception) {
            return 'failed: '.$this->redactor->redact($exception->getMessage());
        }
    }

    /**
     * Once a restore is settled its safety backup is protected for the
     * configured window instead of indefinitely.
     */
    private function settleSafetyPin(RestoreJournal $journal): string
    {
        $uuid = $journal->safetyRunUuid();
        $settled = $journal->settledAt();

        if ($uuid === null) {
            return 'no_safety_backup';
        }

        if ($settled === null) {
            return 'kept_indefinitely_while_unresolved';
        }

        try {
            $run = $this->catalog->has('quraba_backup_runs') ? BackupRun::query()->where('uuid', $uuid)->first() : null;

            if ($run === null) {
                return 'not_in_local_catalog';
            }

            $days = $this->config->get('quraba-backup.retention.safety_days', 30);
            $until = $settled->addDays(is_int($days) && $days >= 1 ? $days : 30);
            $run->pin($until, 'Pre-change safety backup of settled restore '.$journal->restoreUuid().'.');

            return 'protected_until_'.$until->toIso8601ZuluString();
        } catch (Throwable $exception) {
            return 'failed: '.$this->redactor->redact($exception->getMessage());
        }
    }

    /**
     * Removes the parked pre-restore trees of a COMPLETED restore: exactly
     * the paths the journal recorded, each a sibling of its live root named
     * for this restore. Link-safe; anything else is refused.
     *
     * @return array{0: RestoreJournal, 1: list<array<string, mixed>>}
     */
    private function removeParked(RestoreJournal $journal): array
    {
        $removed = [];
        $pattern = '/^\..+'.preg_quote(ExactDirectoryReplacement::PARKED_MARKER.$journal->restoreUuid(), '/').'$/';

        foreach ($journal->media() as $name => $root) {
            $parked = is_string($root['parked'] ?? null) ? $root['parked'] : null;
            $live = is_string($root['live'] ?? null) ? $root['live'] : '';

            if ($parked === null) {
                continue;
            }

            if (! file_exists($parked) && ! is_link($parked)) {
                $removed[] = ['root' => $name, 'path' => $parked, 'result' => 'already_absent'];

                continue;
            }

            $base = PathGuard::real(dirname($parked));
            $errors = $base === null || dirname($parked) !== dirname($live) || $parked !== $this->directories->parkedPath($live, $journal->restoreUuid())
                ? ['the parked path is not the sibling this restore created']
                : WorkspaceDeleter::deleteTree($parked, $base, $pattern);

            $removed[] = ['root' => $name, 'path' => $parked, 'result' => $errors === [] ? 'removed' : 'failed', 'errors' => $errors];
        }

        $journal = $this->journals->save($journal->withAnnotation('parked_cleanup', ['at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'roots' => $removed]));

        return [$journal, $removed];
    }
}

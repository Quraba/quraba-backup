<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Journal;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Restore\RestoreSource;
use Throwable;

/**
 * The durable, external record of ONE live restore — the primary authority
 * about what a live restore did to the application.
 *
 * It lives outside the application database (which a restore replaces, and
 * which on a clean host does not even contain the package tables), holds no
 * secret, and is only ever moved forward: every change produces the next
 * sequence number, phases and component states never go back, and frozen
 * identities never change. {@see self::assertSuccessorOf()} enforces that
 * on every write.
 *
 * The object is immutable; each `with*`/`record*` method returns the next
 * version, which {@see RestoreJournalStore::save()} persists.
 */
final readonly class RestoreJournal
{
    public const int SCHEMA_VERSION = 1;

    public const string TYPE = 'quraba-backup-restore-journal';

    public const string SAFETY_REQUIRED = 'required';

    public const string SAFETY_NOT_REQUIRED = 'not_required_target_proven_empty';

    public const string TERMINAL_COMPLETED = 'completed';

    public const string TERMINAL_FAILED = 'failed';

    public const string TERMINAL_INDETERMINATE = 'indeterminate';

    public const string RESOLUTION_ABANDONED = 'abandoned';

    /** Database states in the only order they may be recorded. */
    public const array DATABASE_STATES = ['pending', 'clear_starting', 'cleared', 'import_starting', 'import_completed', 'verified'];

    /** Media root states in the only order they may be recorded. */
    public const array ROOT_STATES = ['pending', 'staged', 'swap_starting', 'parked', 'activated', 'verified'];

    private const array IMMUTABLE = ['schema_version', 'type', 'restore_uuid', 'created_at', 'app_id', 'environment', 'profile', 'clean_host', 'source'];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data) {}

    public static function open(string $restoreUuid, ApplicationIdentity $identity, RestoreProfile $profile, RestoreSource $source, bool $cleanHost): self
    {
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();

        return self::fromArray([
            'schema_version' => self::SCHEMA_VERSION,
            'type' => self::TYPE,
            'restore_uuid' => $restoreUuid,
            'sequence' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'app_id' => $identity->appId,
            'environment' => $identity->environment,
            'profile' => $profile->value,
            'clean_host' => $cleanHost,
            'source' => [
                'run_uuid' => $source->runUuid,
                'backup_profile' => $source->backupProfile,
                'consistency' => $source->consistency,
                'origin' => $source->origin,
                'archive_locator' => $profile === RestoreProfile::Media ? null : $source->archiveLocator,
                'archive_sha256' => $profile === RestoreProfile::Media ? null : $source->archiveSha256,
                'archive_bytes' => $profile === RestoreProfile::Media ? null : $source->archiveBytes,
                'repository_id' => $profile === RestoreProfile::Database ? null : $source->repositoryId,
                'snapshot_id' => $profile === RestoreProfile::Database ? null : $source->snapshotId,
                'snapshot_kind' => $profile === RestoreProfile::Database ? null : $source->snapshotKind,
            ],
            'phase' => JournalPhase::Created->value,
            'destructive_started_at' => null,
            'quiescence' => null,
            'safety_backup' => ['requirement' => $cleanHost ? self::SAFETY_NOT_REQUIRED : self::SAFETY_REQUIRED, 'status' => 'pending', 'run_uuid' => null, 'profile' => null, 'evidence' => null],
            'database' => null,
            'media' => [],
            'verification' => null,
            'terminal' => null,
            'resolution' => null,
            'annotations' => [],
            'history' => [['sequence' => 1, 'at' => $now, 'event' => 'created']],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when the document is not a valid journal
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($data['type'] ?? null) !== self::TYPE) {
            throw new InvalidArgumentException('not a restore journal of a supported schema version');
        }

        foreach (['restore_uuid', 'app_id'] as $key) {
            if (! is_string($data[$key] ?? null) || ! Identifiers::isUuid($data[$key])) {
                throw new InvalidArgumentException(sprintf('invalid %s', $key));
            }
        }

        $source = $data['source'] ?? null;

        if (! is_array($source) || ! is_string($source['run_uuid'] ?? null) || ! Identifiers::isUuid($source['run_uuid'])) {
            throw new InvalidArgumentException('invalid frozen source');
        }

        foreach (['archive_sha256' => Identifiers::isSha256(...), 'repository_id' => Identifiers::isRepositoryId(...), 'snapshot_id' => Identifiers::isFullSnapshotId(...)] as $key => $valid) {
            $value = $source[$key] ?? null;

            if ($value !== null && (! is_string($value) || ! $valid($value))) {
                throw new InvalidArgumentException(sprintf('invalid frozen %s (full identities only)', $key));
            }
        }

        if (! is_string($data['environment'] ?? null) || ! ApplicationIdentity::isValidEnvironment($data['environment'])
            || ! is_string($data['profile'] ?? null) || RestoreProfile::tryFrom($data['profile']) === null
            || ! is_string($data['phase'] ?? null) || JournalPhase::tryFrom($data['phase']) === null
            || ! is_int($data['sequence'] ?? null) || $data['sequence'] < 1
            || ! is_bool($data['clean_host'] ?? null)
            || ! is_string($data['created_at'] ?? null) || ! is_string($data['updated_at'] ?? null)
            || ! is_array($data['safety_backup'] ?? null) || ! is_array($data['media'] ?? null)
            || ! is_array($data['history'] ?? null) || ! is_array($data['annotations'] ?? null)) {
            throw new InvalidArgumentException('invalid journal structure');
        }

        foreach (['destructive_started_at'] as $key) {
            if (($data[$key] ?? null) !== null && ! is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('invalid %s', $key));
            }
        }

        $database = $data['database'] ?? null;

        if ($database !== null && (! is_array($database) || ! in_array($database['state'] ?? null, self::DATABASE_STATES, true))) {
            throw new InvalidArgumentException('invalid database state');
        }

        foreach ($data['media'] as $name => $root) {
            if (! is_string($name) || ! is_array($root) || ! in_array($root['state'] ?? null, self::ROOT_STATES, true)) {
                throw new InvalidArgumentException('invalid media root state');
            }
        }

        foreach (['terminal' => [self::TERMINAL_COMPLETED, self::TERMINAL_FAILED, self::TERMINAL_INDETERMINATE], 'resolution' => [self::TERMINAL_COMPLETED, self::TERMINAL_FAILED, self::RESOLUTION_ABANDONED]] as $key => $allowed) {
            $value = $data[$key] ?? null;

            if ($value !== null && (! is_array($value) || ! in_array($value[$key === 'terminal' ? 'state' : 'outcome'] ?? null, $allowed, true))) {
                throw new InvalidArgumentException(sprintf('invalid %s', $key));
            }
        }

        $typed = [];

        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return new self($typed);
    }

    /**
     * Refuses anything that is not the direct, forward-only successor of the
     * version currently on disk.
     *
     * @throws InvalidArgumentException
     */
    public function assertSuccessorOf(self $current): void
    {
        if ($this->sequence() !== $current->sequence() + 1) {
            throw new InvalidArgumentException(sprintf('sequence %d does not follow %d', $this->sequence(), $current->sequence()));
        }

        foreach (self::IMMUTABLE as $key) {
            if ($this->data[$key] !== $current->data[$key]) {
                throw new InvalidArgumentException(sprintf('frozen field [%s] changed', $key));
            }
        }

        if ($this->phase()->rank() < $current->phase()->rank()) {
            throw new InvalidArgumentException('the phase moved backwards');
        }

        if ($current->destructiveStartedAt() !== null && $this->destructiveStartedAt() !== $current->destructiveStartedAt()) {
            throw new InvalidArgumentException('the destructive boundary cannot be moved or removed');
        }

        if ($current->safetyRunUuid() !== null && $this->safetyRunUuid() !== $current->safetyRunUuid()) {
            throw new InvalidArgumentException('the safety backup of a restore cannot be replaced');
        }

        if ($current->databaseState() !== null && self::rank(self::DATABASE_STATES, $this->databaseState()) < self::rank(self::DATABASE_STATES, $current->databaseState())) {
            throw new InvalidArgumentException('the database state moved backwards');
        }

        foreach ($current->rootNames() as $name) {
            if (self::rank(self::ROOT_STATES, $this->rootState($name)) < self::rank(self::ROOT_STATES, $current->rootState($name))) {
                throw new InvalidArgumentException(sprintf('media root [%s] moved backwards', $name));
            }
        }

        if ($current->terminalState() !== null) {
            // After a terminal state only a resolution and annotations may be added.
            foreach (['phase', 'destructive_started_at', 'quiescence', 'safety_backup', 'database', 'media', 'verification', 'terminal'] as $key) {
                if ($this->data[$key] !== $current->data[$key]) {
                    throw new InvalidArgumentException(sprintf('[%s] changed after the restore reached a terminal state', $key));
                }
            }

            if ($current->data['resolution'] !== null && $this->data['resolution'] !== $current->data['resolution']) {
                throw new InvalidArgumentException('a resolution cannot be replaced');
            }
        }

        if (array_slice($this->history(), 0, count($current->history())) !== $current->history() || count($this->history()) !== count($current->history()) + 1) {
            throw new InvalidArgumentException('the history must only be appended to');
        }
    }

    public function withPhase(JournalPhase $phase): self
    {
        return $this->next($phase->value, ['phase' => $phase->value]);
    }

    /**
     * @param  array<string, mixed>  $quiescence
     */
    public function withQuiescence(array $quiescence): self
    {
        return $this->next(JournalPhase::Quiesced->value, ['phase' => JournalPhase::Quiesced->value, 'quiescence' => $quiescence]);
    }

    /**
     * The safety backup run is announced BEFORE it runs, so a crash while it
     * is being taken leaves the linkage behind.
     */
    public function withSafetyBackupStarting(string $backupProfile, string $runUuid): self
    {
        return $this->next(JournalPhase::SafetyBackupStarting->value, [
            'phase' => JournalPhase::SafetyBackupStarting->value,
            'safety_backup' => [...$this->safetyBackup(), 'status' => 'running', 'profile' => $backupProfile, 'run_uuid' => Identifiers::assertUuid($runUuid, 'The safety backup run UUID')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function withSafetyBackupVerified(array $evidence): self
    {
        if ($this->safetyRunUuid() === null) {
            throw new InvalidArgumentException('no safety backup run was announced');
        }

        return $this->next(JournalPhase::SafetyBackupVerified->value, [
            'phase' => JournalPhase::SafetyBackupVerified->value,
            'safety_backup' => [...$this->safetyBackup(), 'status' => 'verified', 'evidence' => $evidence],
        ]);
    }

    /**
     * Clean host only: the target was proven empty, nothing can be lost.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function withSafetyBackupNotRequired(array $evidence): self
    {
        if (! $this->isCleanHost()) {
            throw new InvalidArgumentException('only a declared clean-host restore may skip the safety backup');
        }

        return $this->next('safety_backup_not_required', [
            'phase' => JournalPhase::SafetyBackupVerified->value,
            'safety_backup' => [...$this->safetyBackup(), 'requirement' => self::SAFETY_NOT_REQUIRED, 'status' => 'not_required', 'evidence' => $evidence],
        ]);
    }

    /**
     * @param  array<string, mixed>  $database  target identity, inventory and expectations
     */
    public function withDatabasePlan(array $database): self
    {
        return $this->next('db_planned', ['database' => [...$database, 'state' => 'pending']]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function withDatabaseState(string $state, array $extra = []): self
    {
        $database = $this->data['database'];

        if (! is_array($database) || ! in_array($state, self::DATABASE_STATES, true)) {
            throw new InvalidArgumentException('no database is part of this restore, or the state is unknown');
        }

        $phase = match ($state) {
            'clear_starting' => JournalPhase::DatabaseClearStarting,
            'cleared' => JournalPhase::DatabaseCleared,
            'import_starting' => JournalPhase::DatabaseImportStarting,
            'import_completed' => JournalPhase::DatabaseImportCompleted,
            'verified' => JournalPhase::DatabaseVerified,
            default => $this->phase(),
        };

        return $this->next('db_'.$state, [
            'phase' => $phase->value,
            'database' => [...$database, ...$extra, 'state' => $state],
            // The first *_starting record of a component IS the destructive boundary.
            'destructive_started_at' => $this->destructiveStartedAt() ?? ($state === 'clear_starting' ? CarbonImmutable::now('UTC')->toIso8601ZuluString() : null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $root  live destination, staged tree and its fingerprint
     */
    public function withRootPlan(string $name, array $root): self
    {
        return $this->next('root:'.$name.':planned', ['media' => [...$this->media(), $name => [...$root, 'state' => 'pending']]]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function withRootState(string $name, string $state, array $extra = []): self
    {
        $media = $this->media();

        if (! isset($media[$name]) || ! in_array($state, self::ROOT_STATES, true)) {
            throw new InvalidArgumentException(sprintf('media root [%s] is not part of this restore, or the state is unknown', $name));
        }

        $media[$name] = [...$media[$name], ...$extra, 'state' => $state];

        return $this->next('root:'.$name.':'.$state, [
            'phase' => (in_array($state, ['pending', 'staged'], true) || $this->phase()->rank() >= JournalPhase::MediaApplying->rank() ? $this->phase() : JournalPhase::MediaApplying)->value,
            'media' => $media,
            'destructive_started_at' => $this->destructiveStartedAt() ?? ($state === 'swap_starting' ? CarbonImmutable::now('UTC')->toIso8601ZuluString() : null),
        ]);
    }

    /**
     * After a database import the restore's own audit row was handed back
     * into the restored catalog (or could not be, which is recorded too).
     */
    public function withAuditHandback(string $status): self
    {
        return $this->next(JournalPhase::AuditRestored->value, [
            'phase' => JournalPhase::AuditRestored->value,
            'annotations' => [...$this->annotations(), 'audit_handback' => $status],
        ]);
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    public function withVerification(array $verification): self
    {
        return $this->next(JournalPhase::Verified->value, ['phase' => JournalPhase::Verified->value, 'verification' => $verification]);
    }

    public function withTerminal(string $state, ?FailureDetails $failure = null): self
    {
        if ($state === self::TERMINAL_FAILED && $this->crossedDestructiveBoundary()) {
            throw new InvalidArgumentException('a restore that crossed its destructive boundary cannot be recorded as failed; it is indeterminate until proven otherwise');
        }

        if ($state === self::TERMINAL_COMPLETED && $this->phase() !== JournalPhase::Verified) {
            throw new InvalidArgumentException('only a verified restore can be recorded as completed');
        }

        return $this->next('terminal:'.$state, ['terminal' => [
            'state' => $state,
            'at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'failure' => $failure?->toArray(),
        ]]);
    }

    /**
     * Explicit outcome of reconciliation (or of an operator's decision to
     * abandon an indeterminate restore).
     *
     * @param  array<string, mixed>  $evidence
     */
    public function withResolution(string $outcome, array $evidence): self
    {
        if ($outcome === self::TERMINAL_FAILED && $this->crossedDestructiveBoundary()) {
            throw new InvalidArgumentException('a restore that crossed its destructive boundary cannot be resolved as failed');
        }

        if ($evidence === []) {
            throw new InvalidArgumentException('a resolution requires evidence');
        }

        $changes = ['resolution' => ['outcome' => $outcome, 'at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'evidence' => $evidence]];

        // A journal whose process died has no terminal record yet.
        if ($this->terminalState() === null) {
            $changes['terminal'] = ['state' => $this->crossedDestructiveBoundary() ? self::TERMINAL_INDETERMINATE : self::TERMINAL_FAILED, 'at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'failure' => ['code' => 'restore.interrupted', 'stage' => 'restore.reconcile', 'message' => 'The restore process ended without recording a terminal state.']];
        }

        return $this->next('resolution:'.$outcome, $changes);
    }

    public function withAnnotation(string $key, mixed $value): self
    {
        return $this->next('annotation:'.$key, ['annotations' => [...$this->annotations(), $key => $value]]);
    }

    public function restoreUuid(): string
    {
        return self::string($this->data['restore_uuid']);
    }

    public function sequence(): int
    {
        return is_int($this->data['sequence']) ? $this->data['sequence'] : 0;
    }

    public function appId(): string
    {
        return self::string($this->data['app_id']);
    }

    public function environment(): string
    {
        return self::string($this->data['environment']);
    }

    public function profile(): RestoreProfile
    {
        return RestoreProfile::from(self::string($this->data['profile']));
    }

    public function isCleanHost(): bool
    {
        return $this->data['clean_host'] === true;
    }

    public function phase(): JournalPhase
    {
        return JournalPhase::from(self::string($this->data['phase']));
    }

    public function sourceRunUuid(): string
    {
        return self::string($this->source()['run_uuid'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function source(): array
    {
        return self::map($this->data['source']);
    }

    public function destructiveStartedAt(): ?string
    {
        return is_string($this->data['destructive_started_at']) ? $this->data['destructive_started_at'] : null;
    }

    public function crossedDestructiveBoundary(): bool
    {
        return $this->destructiveStartedAt() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function safetyBackup(): array
    {
        return self::map($this->data['safety_backup']);
    }

    public function safetyRunUuid(): ?string
    {
        $uuid = $this->safetyBackup()['run_uuid'] ?? null;

        return is_string($uuid) ? $uuid : null;
    }

    public function safetyRequired(): bool
    {
        return ($this->safetyBackup()['requirement'] ?? null) !== self::SAFETY_NOT_REQUIRED;
    }

    public function safetyVerified(): bool
    {
        return ($this->safetyBackup()['status'] ?? null) === 'verified';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function quiescence(): ?array
    {
        return is_array($this->data['quiescence']) ? self::map($this->data['quiescence']) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function database(): ?array
    {
        return is_array($this->data['database']) ? self::map($this->data['database']) : null;
    }

    public function databaseState(): ?string
    {
        $state = $this->database()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function media(): array
    {
        $media = [];

        foreach (self::map($this->data['media']) as $name => $root) {
            $media[$name] = self::map($root);
        }

        return $media;
    }

    /**
     * @return list<string>
     */
    public function rootNames(): array
    {
        return array_keys($this->media());
    }

    public function rootState(string $name): ?string
    {
        $state = $this->media()[$name]['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function terminal(): ?array
    {
        return is_array($this->data['terminal']) ? self::map($this->data['terminal']) : null;
    }

    public function terminalState(): ?string
    {
        $state = $this->terminal()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolution(): ?array
    {
        return is_array($this->data['resolution']) ? self::map($this->data['resolution']) : null;
    }

    public function resolutionOutcome(): ?string
    {
        $outcome = $this->resolution()['outcome'] ?? null;

        return is_string($outcome) ? $outcome : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return self::map($this->data['annotations']);
    }

    /**
     * @return list<mixed>
     */
    public function history(): array
    {
        return is_array($this->data['history']) ? array_values($this->data['history']) : [];
    }

    /**
     * A restore that is still running, died, or ended indeterminate and was
     * never explicitly resolved. It blocks every further live restore.
     */
    public function isUnresolved(): bool
    {
        return match ($this->terminalState()) {
            null => true,
            self::TERMINAL_INDETERMINATE => $this->resolution() === null,
            default => false,
        };
    }

    /**
     * The effective outcome: an explicit resolution wins over the terminal
     * record; null while the restore is unresolved.
     */
    public function outcome(): ?string
    {
        return $this->resolutionOutcome() ?? ($this->isUnresolved() ? null : $this->terminalState());
    }

    /**
     * When the outcome was settled for good — the start of the retention
     * window of the safety backup — or null while that window has not begun:
     * a failed or indeterminate restore keeps its safety backup indefinitely
     * until it is explicitly resolved.
     */
    public function settledAt(): ?CarbonImmutable
    {
        $at = $this->resolution()['at'] ?? ($this->terminalState() === self::TERMINAL_COMPLETED ? ($this->terminal()['at'] ?? null) : null);

        if (! is_string($at)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($at)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Non-secret operator view: enough to identify an unresolved restore.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'restore_uuid' => $this->restoreUuid(),
            'profile' => $this->profile()->value,
            'clean_host' => $this->isCleanHost(),
            'source_run_uuid' => $this->sourceRunUuid(),
            'safety_backup_run_uuid' => $this->safetyRunUuid(),
            'safety_backup' => $this->safetyBackup()['status'] ?? null,
            'phase' => $this->phase()->value,
            'destructive_started_at' => $this->destructiveStartedAt(),
            'database' => $this->databaseState(),
            'media' => array_map(static fn (array $root): mixed => $root['state'] ?? null, $this->media()),
            'terminal' => $this->terminalState(),
            'resolution' => $this->resolutionOutcome(),
            'unresolved' => $this->isUnresolved(),
            'updated_at' => $this->data['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function next(string $event, array $changes): self
    {
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();
        $sequence = $this->sequence() + 1;

        return self::fromArray([
            ...$this->data,
            ...$changes,
            'sequence' => $sequence,
            'updated_at' => $now,
            'history' => [...$this->history(), ['sequence' => $sequence, 'at' => $now, 'event' => $event]],
        ]);
    }

    /**
     * @param  list<string>  $order
     */
    private static function rank(array $order, ?string $state): int
    {
        $rank = array_search($state, $order, true);

        return $rank === false ? -1 : $rank;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $typed = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }
}

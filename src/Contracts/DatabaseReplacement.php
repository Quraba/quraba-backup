<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Closure;
use Quraba\Backup\Database\DatabaseTarget;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Workspace\OperationWorkspace;

/**
 * Exact replacement of the live application database: clear precisely the
 * inventoried objects, import the validated dump, verify the result. There
 * is no merge restore and no `DROP DATABASE`.
 *
 * Every mutating method re-proves the target itself and refuses when it is
 * no longer the target proven at preflight. Only the live restore service
 * calls this, and only after the journal recorded the step.
 */
interface DatabaseReplacement
{
    /**
     * Proves which database a restore would replace: the configured
     * production connection, the database name the server reports, not a
     * system schema and not the scratch validation database.
     *
     * @throws RestoreFailed
     */
    public function target(): DatabaseTarget;

    /**
     * Proves, BEFORE anything is changed, that the validated dump can be
     * imported into the target by the restoring account — in particular
     * that this account may create the views, triggers and routines the dump
     * defines for other accounts (DEFINER).
     *
     * @return array<string, mixed> non-secret facts about how the dump will be imported
     *
     * @throws RestoreFailed
     */
    public function assertImportable(DatabaseTarget $target, string $dump): array;

    /**
     * @throws RestoreFailed when the target changed
     */
    public function inventory(DatabaseTarget $target): SchemaInventory;

    /**
     * The deterministic schema fingerprint of the proven target as it is
     * now (read-only). Restore reconciliation compares it with the journal.
     *
     * @throws RestoreFailed when the target changed
     */
    public function fingerprint(DatabaseTarget $target): string;

    /**
     * Drops exactly the inventoried objects of the proven target.
     *
     * @param  Closure(string, string): void  $dropped  called after each dropped object (type, name)
     *
     * @throws RestoreFailed
     */
    public function clear(DatabaseTarget $target, SchemaInventory $inventory, Closure $dropped): void;

    /**
     * Imports the validated dump into the proven, cleared target. A clean
     * client exit is necessary, never sufficient: see {@see self::verify()}.
     *
     * @throws RestoreFailed
     */
    public function import(DatabaseTarget $target, string $dump, OperationWorkspace $workspace): void;

    /**
     * Reconnects and proves the imported database against the dump and the
     * backup's own metadata.
     *
     * @param  array<string, mixed>  $metadata  the `database` section of the archive metadata
     * @return array<string, mixed> non-secret verification evidence
     *
     * @throws RestoreFailed
     */
    public function verify(DatabaseTarget $target, string $dump, array $metadata, ?string $scratchFingerprint): array;
}

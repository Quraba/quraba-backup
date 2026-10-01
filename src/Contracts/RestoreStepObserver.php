<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

/**
 * Notified at every named step of restore preparation and live restore
 * (e.g. `db.clear_starting`, `media.parked`, `scratch.cleanup_starting`).
 *
 * The default implementation does nothing. An application may bind its own
 * observer to report progress; the test suite binds one that fails at a
 * chosen step to prove what the restore does when it is interrupted there.
 *
 * An observer that throws aborts the restore AT THAT STEP exactly like any
 * other failure: before the destructive boundary the restore fails, after
 * it the restore becomes indeterminate. It can never make a restore skip a
 * safety check.
 */
interface RestoreStepObserver
{
    /**
     * @param  array<string, scalar|null>  $context  non-secret facts about the step
     */
    public function reached(string $step, array $context = []): void;
}

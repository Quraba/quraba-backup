<?php

declare(strict_types=1);

namespace Quraba\Backup\Models\Concerns;

use LogicException;
use Quraba\Backup\Domain\StatusMachine;
use Quraba\Backup\Exceptions\IllegalStateTransition;

/**
 * Makes `status` writable only through declared transitions.
 *
 * - Direct assignment (`$model->status = ...`, `fill()`, `update()`,
 *   `forceFill()`) throws.
 * - A transition is only applied when the enum declares it legal.
 * - The write is a compare-and-set against the previously loaded status, so
 *   two processes racing on the same record cannot both win.
 *
 * The persisted `uuid` is immutable once the record exists.
 */
trait HasControlledStatus
{
    private bool $statusWriteAllowed = false;

    public function setAttribute($key, $value)
    {
        if ($key === 'status' && ! $this->statusWriteAllowed) {
            throw new IllegalStateTransition(sprintf(
                'The status of %s can only change through its transition methods.',
                class_basename($this),
            ));
        }

        if ($key === 'uuid' && $this->exists && $this->getRawOriginal('uuid') !== null && $value !== $this->getRawOriginal('uuid')) {
            throw new IllegalStateTransition(sprintf('The UUID of %s is immutable.', class_basename($this)));
        }

        return parent::setAttribute($key, $value);
    }

    public function currentStatus(): StatusMachine
    {
        $status = $this->getAttribute('status');

        if (! $status instanceof StatusMachine) {
            throw new LogicException(sprintf('%s has no valid status loaded.', class_basename($this)));
        }

        return $status;
    }

    /**
     * Assigns the initial status on a model that has not been persisted yet.
     */
    protected function initializeStatus(StatusMachine $initial): void
    {
        if ($this->exists) {
            throw new LogicException('The initial status can only be assigned before the record is created.');
        }

        $this->withStatusWrite(fn () => parent::setAttribute('status', $initial));
    }

    /**
     * @param  array<string, mixed>  $attributes  additional attributes written atomically with the status
     */
    protected function transitionTo(StatusMachine $next, array $attributes = []): static
    {
        $current = $this->currentStatus();

        if (! $current->canTransitionTo($next)) {
            throw new IllegalStateTransition(sprintf(
                'Illegal %s transition from [%s] to [%s].',
                class_basename($this),
                $current->value,
                $next->value,
            ));
        }

        return $this->writeStatus($current, $next, $attributes);
    }

    /**
     * Writes a status change that bypasses the normal transition graph. Only
     * used for reconciliation of indeterminate records, whose callers must
     * validate the outcome themselves.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function forceReconciledStatus(StatusMachine $next, array $attributes = []): static
    {
        return $this->writeStatus($this->currentStatus(), $next, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeStatus(StatusMachine $current, StatusMachine $next, array $attributes): static
    {
        if (! $this->exists) {
            throw new LogicException(sprintf('%s must be persisted before its status can change.', class_basename($this)));
        }

        $this->withStatusWrite(fn () => parent::setAttribute('status', $next));

        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $changes = $this->getDirty();

        $affected = $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->where('status', $current->value)
            ->toBase()
            ->update($changes);

        if ($affected !== 1) {
            $this->refresh();

            throw new IllegalStateTransition(sprintf(
                '%s [%s] changed concurrently; expected status [%s] was no longer current.',
                class_basename($this),
                is_string($uuid = $this->getAttribute('uuid')) ? $uuid : '?',
                $current->value,
            ));
        }

        $this->syncOriginal();

        return $this;
    }

    private function withStatusWrite(callable $callback): void
    {
        $this->statusWriteAllowed = true;

        try {
            $callback();
        } finally {
            $this->statusWriteAllowed = false;
        }
    }
}

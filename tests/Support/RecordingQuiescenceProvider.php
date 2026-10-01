<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Enums\ConsistencyLevel;
use RuntimeException;

/**
 * A provider that proves quiescence (or not) on demand and can fail release.
 * Like maintenance mode, only the FIRST open session really enters: a nested
 * session (the safety backup inside a live restore) finds the application
 * already quiesced and leaves it that way.
 */
final class RecordingQuiescenceProvider implements QuiescenceProvider
{
    public int $entered = 0;

    public int $released = 0;

    /** Whether the application is currently held quiesced by a session of this provider. */
    public bool $active = false;

    public function __construct(
        private readonly ConsistencyLevel $level = ConsistencyLevel::Quiesced,
        private readonly bool $failRelease = false,
        private readonly bool $claims = true,
    ) {}

    public function name(): string
    {
        return 'recording';
    }

    public function claimsQuiescence(): bool
    {
        return $this->claims && $this->level === ConsistencyLevel::Quiesced;
    }

    public function enter(): QuiescenceSession
    {
        $this->entered++;
        $enteredHere = ! $this->active;
        $this->active = true;

        return new QuiescenceSession('recording', $this->level, 'test provider', function () use ($enteredHere): void {
            $this->released++;

            if ($this->failRelease) {
                throw new RuntimeException('simulated release failure');
            }

            if ($enteredHere) {
                $this->active = false;
            }
        }, fn (): bool => $this->active, $enteredHere);
    }
}

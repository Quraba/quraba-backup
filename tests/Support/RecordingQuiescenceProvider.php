<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Enums\ConsistencyLevel;
use RuntimeException;

/**
 * A provider that proves quiescence (or not) on demand and can fail release.
 */
final class RecordingQuiescenceProvider implements QuiescenceProvider
{
    public int $entered = 0;

    public int $released = 0;

    public function __construct(
        private readonly ConsistencyLevel $level = ConsistencyLevel::Quiesced,
        private readonly bool $failRelease = false,
    ) {}

    public function name(): string
    {
        return 'recording';
    }

    public function enter(): QuiescenceSession
    {
        $this->entered++;

        return new QuiescenceSession('recording', $this->level, 'test provider', function (): void {
            $this->released++;

            if ($this->failRelease) {
                throw new RuntimeException('simulated release failure');
            }
        }, static fn (): bool => true);
    }
}

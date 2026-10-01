<?php

declare(strict_types=1);

namespace Quraba\Backup\Consistency;

use Closure;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\QuiescenceFailed;
use Throwable;

/**
 * An entered quiescence window. leave() is idempotent and must be called in
 * a finally block.
 */
final class QuiescenceSession
{
    private bool $left = false;

    /**
     * @param  Closure(): void  $release
     * @param  Closure(): bool  $stillQuiesced
     */
    public function __construct(
        public readonly string $provider,
        public readonly ConsistencyLevel $level,
        public readonly string $explanation,
        private readonly Closure $release,
        private readonly Closure $stillQuiesced,
        /** False when the application was already quiesced before enter(): leave() then changes nothing. */
        public readonly bool $enteredHere = true,
    ) {}

    public static function none(string $provider, string $explanation): self
    {
        return new self($provider, ConsistencyLevel::BestEffort, $explanation, static function (): void {}, static fn (): bool => true);
    }

    /**
     * Re-checks the claimed condition before the capture is considered done.
     *
     * @throws QuiescenceFailed
     */
    public function assertStillQuiesced(): void
    {
        if ($this->level === ConsistencyLevel::Quiesced && ! ($this->stillQuiesced)()) {
            throw new QuiescenceFailed(sprintf('The application left the quiesced state during capture (%s).', $this->provider));
        }
    }

    /**
     * @throws QuiescenceFailed when releasing fails
     */
    public function leave(): void
    {
        if ($this->left) {
            return;
        }

        $this->left = true;

        try {
            ($this->release)();
        } catch (Throwable $exception) {
            throw new QuiescenceFailed(sprintf('Leaving quiescence (%s) failed: %s', $this->provider, $exception->getMessage()));
        }
    }

    public function hasLeft(): bool
    {
        return $this->left;
    }
}

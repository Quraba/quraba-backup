<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\RestoreFailed;

/**
 * Proof that an operator explicitly authorized a LIVE restore.
 *
 * The live restore service cannot be started without one, and one can only
 * be obtained by supplying BOTH the force flag and the exact, configured
 * confirmation phrase. An interactive yes/no is not enough; a phrase that
 * differs by case or whitespace is not enough.
 */
final readonly class LiveRestoreAuthorization
{
    public const string DEFAULT_PHRASE = 'RESTORE_APPLICATION';

    private function __construct(public bool $cleanHost) {}

    public static function phrase(Repository $config): string
    {
        $phrase = $config->get('quraba-backup.restore.confirmation_phrase', self::DEFAULT_PHRASE);

        // A blank configured phrase must never make every restore "confirmed".
        return is_string($phrase) && strlen(trim($phrase)) >= 8 ? $phrase : self::DEFAULT_PHRASE;
    }

    /**
     * @throws RestoreFailed
     */
    public static function confirm(Repository $config, bool $force, mixed $confirmation, bool $cleanHost = false): self
    {
        $phrase = self::phrase($config);

        if (! $force) {
            throw RestoreFailed::confirmationRequired('--force was not given');
        }

        if (! is_string($confirmation) || ! hash_equals($phrase, $confirmation)) {
            throw RestoreFailed::confirmationRequired(sprintf('re-run with --force --confirm=%s (the phrase must match exactly)', $phrase));
        }

        return new self($cleanHost);
    }
}

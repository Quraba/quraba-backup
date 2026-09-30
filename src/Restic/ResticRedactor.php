<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Security\SecretRedactor;

/**
 * Sanitizes everything that comes out of a Restic process before it can be
 * returned, logged, persisted or shown to an operator.
 *
 * On top of the package-wide secret redaction it removes terminal control
 * sequences and bounds diagnostic text, keeping the tail (where Restic puts
 * the fatal error) so diagnostics stay useful.
 */
final readonly class ResticRedactor
{
    public const int DIAGNOSTIC_LIMIT = 4000;

    public function __construct(private SecretRedactor $secrets) {}

    public function redact(string $output): string
    {
        return $this->secrets->redact($output);
    }

    /**
     * A bounded, single-purpose diagnostic string for exception messages.
     */
    public function diagnostic(string $output, int $limit = self::DIAGNOSTIC_LIMIT): string
    {
        $clean = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $output);
        $clean = (string) preg_replace('/\r(?!\n)/', "\n", $clean);
        $clean = trim($this->secrets->redact($clean));

        if (strlen($clean) > $limit) {
            $clean = '…'.substr($clean, -$limit);
        }

        // Redact once more: truncation can never re-expose anything, but a
        // cut could otherwise turn a partially masked token into a new match.
        return $this->secrets->redact($clean);
    }
}

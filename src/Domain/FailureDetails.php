<?php

declare(strict_types=1);

namespace Quraba\Backup\Domain;

use InvalidArgumentException;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * A failure as it is persisted: a stable machine-readable code, the stage in
 * which it happened, and a sanitized, bounded human message.
 *
 * The only way to build one is through a redactor, so a failure message can
 * never reach the catalog unsanitized.
 */
final readonly class FailureDetails
{
    public const int MAX_MESSAGE_LENGTH = 2000;

    private const string IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    private function __construct(
        public string $code,
        public string $stage,
        public string $message,
    ) {}

    public static function make(string $code, string $stage, string $message, SecretRedactor $redactor): self
    {
        self::assertIdentifier($code, 'failure code');
        self::assertIdentifier($stage, 'failure stage');

        $message = $redactor->redact(mb_scrub($message, 'UTF-8'));
        $message = (string) preg_replace('/[^\P{C}\n\t]/u', '', $message);
        $message = trim($message);

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 1).'…';
        }

        return new self($code, $stage, $message === '' ? 'No diagnostic message was provided.' : $message);
    }

    /**
     * Package exceptions keep their own failure code; anything else is
     * recorded as an unexpected error without trusting its class name.
     */
    public static function fromThrowable(Throwable $exception, string $stage, SecretRedactor $redactor): self
    {
        $code = $exception instanceof QurabaBackupException ? $exception->failureCode() : 'unexpected.error';

        return self::make($code, $stage, $exception->getMessage(), $redactor);
    }

    /**
     * @return array{code: string, stage: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'stage' => $this->stage, 'message' => $this->message];
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (strlen($value) > 64 || preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s [%s]; expected a dotted snake_case identifier of at most 64 characters.', $label, substr($value, 0, 64)));
        }
    }
}

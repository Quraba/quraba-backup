<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function dump(): string
    {
        return (string) json_encode($this->records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

/**
 * Outcome of removing a workspace. Cleanup never throws, so a cleanup
 * failure can be reported without masking the operation's primary error,
 * and never rewrites a verified artifact as failed.
 */
final readonly class CleanupReport
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $workspaceId,
        public bool $removed,
        public bool $alreadyAbsent,
        public array $errors = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->errors === [] && ($this->removed || $this->alreadyAbsent);
    }
}

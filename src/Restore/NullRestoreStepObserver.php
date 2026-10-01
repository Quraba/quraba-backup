<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Contracts\RestoreStepObserver;

final class NullRestoreStepObserver implements RestoreStepObserver
{
    public function reached(string $step, array $context = []): void {}
}

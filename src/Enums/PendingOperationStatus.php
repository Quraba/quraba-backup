<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum PendingOperationStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';
    case Interrupted = 'interrupted';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Claimed, self::Running], true);
    }
}

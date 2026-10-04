<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Operations\PendingOperationProcessor;
use Throwable;

final class PendingOperationCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:pending-operations';

    protected $description = 'Process one pending Filament operation and record worker activity.';

    public function handle(PendingOperationProcessor $processor): int
    {
        try {
            $operation = $processor->runOne();
            if ($operation !== null) {
                $this->line('Panel operation '.$operation->uuid.': '.$operation->status->value);
            }

            return $operation === null || $operation->status->value === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }
    }
}
